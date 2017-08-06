<?php
/**
 * @package ChrisVF
 * @version 1.0
 */
/*
Plugin Name: Chris' Ventnor Fringe Hacks
Plugin URI: http://wordpress.org/plugins/hello-dolly/
Description: VFringe Hacks by Chris.
Author: Christopher Gutteridge
Version: 1.0
Author URI: http://users.ecs.soton.ac.uk/cjg/
*/

/* WORDPRESS FUNCTIONS */

add_shortcode('chrisvf_grid', 'chrisvf_render_grid');

add_shortcode('chrisvf_itinerary', 'chrisvf_render_itinerary');
add_shortcode('chrisvf_saved_itinerary', 'chrisvf_render_saved_itinerary');

add_shortcode('chrisvf_itinerary_slug', 'chrisvf_render_itinerary_slug');
add_shortcode('chrisvf_random', 'chrisvf_render_random');

add_action( 'tribe_events_single_event_after_the_content', 'chrisvf_print_itinerary_add' );

add_action( 'wp_enqueue_scripts', 'chrisvf_add_my_stylesheet' );
function chrisvf_add_my_stylesheet() {
    // Respects SSL, Style.css is relative to the current file
    wp_register_style( 'chrisvf-grid', plugins_url('grid.css', __FILE__) );
    wp_enqueue_style( 'chrisvf-grid' );

    wp_register_style( 'chrisvf-itinerary', plugins_url('itinerary.css', __FILE__) );
    wp_enqueue_style( 'chrisvf-itinerary' );

    wp_register_script( 'chrisvf-itinerary', plugins_url('itinerary.js', __FILE__) );
    wp_enqueue_script( 'chrisvf-itinerary' );

    wp_register_script( 'chrisvf-roulette', plugins_url('roulette.min.js', __FILE__) );
    wp_enqueue_script( 'chrisvf-roulette' );
}

/* FRINGE FUNCTIONS */

/* LOAD ICAL */

function chrisvf_get_events() {
  $info = chrisvf_get_info();
  return $info['events'];
}

function chrisvf_get_info() {
  global $chrisvf_cache;
  #print "\n<!-- GET CACHE -->\n";
  if( !@$_GET['redo'] && !empty( $chrisvf_cache ) && !empty( $chrisvf_cache['events'])) {
    #print "\n<!-- ...RAM CACHE -->\n";
    return $chrisvf_cache;
  }
    
  #$ical_url = "http://vfringe.ventnorexchange.co.uk/whatson/?ical=1";
  $cache_file = "/tmp/vfringe-events.json";
  $cache_timeout = 60*30; # 30 minute cache

  if ( !@$_GET['redo'] && file_exists($cache_file) && (filemtime($cache_file) > (time() - $cache_timeout))) {
    #print "\n<!-- ...USE CACHE FILE -->\n";
    $chrisvf_cache = json_decode( file_get_contents( $cache_file ),true);
  } else {
    #print "\n<!-- ...BUILD CACHE FILE -->\n";
    $chrisvf_cache["events"]  = chrisvf_wp_events();
     
    $ob_events = @chrisvf_load_ical( "https://calendar.google.com/calendar/ical/co2vini9rdvmlv46ur167tufm4%40group.calendar.google.com/public/basic.ics" );
    if( !empty( $ob_events ) ) {
      $chrisvf_cache["ob_events"] = $ob_events;
    }
    foreach( $chrisvf_cache["ob_events"] as $event ) {
      $event["LOCATION"] = "The Observatory Bar";
      $event["LOCID"] = 8;
      if( !preg_match( '/£/', $event["SUMMARY"] ) ) { $event["CATEGORIES"] = "Free Fringe"; }
      $chrisvf_cache["events"][$event["UID"]] = $event;
    }

    $ps_events = @chrisvf_load_ical( "https://calendar.google.com/calendar/ical/l1irfmsvtvgr2phlprdodo2j48%40group.calendar.google.com/public/basic.ics" );
    if( !empty( $ps_events ) ) {
      $chrisvf_cache["ps_events"] = $ps_events;
    }
    foreach( $chrisvf_cache["ps_events"] as $event ) {
      $event["LOCATION"] = "Parkside";
      $event["LOCID"] = 3;
      if( !preg_match( '/£/', $event["SUMMARY"] ) ) { $event["CATEGORIES"] = "Free Fringe"; }
      $chrisvf_cache["events"][$event["UID"]] = $event;
    }

    $chrisvf_cache["venues"] = array();
    foreach( $chrisvf_cache["events"] as $event ) {
      if( empty( $event["LOCATION"] ) ){ continue; }
      $loc = array( "name"=>$event["LOCATION"], "geo"=>@$event["GEO"] );
      $chrisvf_cache["venues"][ $event["LOCATION"] ]  = $loc;
    }

    file_put_contents($cache_file, json_encode($chrisvf_cache), LOCK_EX);
  }

  return $chrisvf_cache;
} 

function chrisvf_wp_events() {
	global $wp_query;

	$tec         = Tribe__Events__Main::instance();
	$args = array(
		'eventDisplay' => 'custom',
		'posts_per_page' => -1,
		'tribeHideRecurrence'=>false,
	);

	// Verify the Intial Category
	if ( $wp_query->get( Tribe__Events__Main::TAXONOMY, false ) !== false ) {
		$args[ Tribe__Events__Main::TAXONOMY ] = $wp_query->get( Tribe__Events__Main::TAXONOMY );
	}

	$events = tribe_get_events( $args );
	$ical = array();
	foreach( $events as $event_post ) {
  		if( $event_post->ID == 1716) { continue; } # before you start
		$full_format = 'Ymd\THis';
		$utc_format = 'Ymd\THis\Z';
		$time = (object) array(
			'start' => tribe_get_start_date( $event_post->ID, false, 'U' ),
			'end' => tribe_get_end_date( $event_post->ID, false, 'U' ),
		);
	
		if ( 'yes' == get_post_meta( $event_post->ID, '_EventAllDay', true ) ) {
			$type = 'DATE';
			$format = 'Ymd';
		} else {
			$type = 'DATE-TIME';
			$format = $full_format;
		}
	
		$tzoned = (object) array(
			'start'    => date( $format, $time->start ),
			'end'      => date( $format, $time->end ),
		);
	
		if ( 'DATE' === $type ){
			$item[ "DTSTART"] = $tzoned->start;
			$item[ "DTEND"] = $tzoned->end;
		} else {
			// Are we using the sitewide timezone or the local event timezone?
			$tz = Tribe__Events__Timezones::EVENT_TIMEZONE === Tribe__Events__Timezones::mode()
				? Tribe__Events__Timezones::get_event_timezone_string( $event_post->ID )
				: Tribe__Events__Timezones::wp_timezone_string();
	
			$item[ 'DTSTART'] = $tzoned->start;
			$item[ 'DTEND' ] = $tzoned->end;
		}
	
		$item[ 'UID' ]= $event_post->ID . '-' . $time->start ;
		$item[ 'SUMMARY' ]= $event_post->post_title ;
		$item[ 'DESCRIPTION' ]= $event_post->post_content ;
		$item[ 'URL' ]= get_permalink( $event_post->ID );

		// add location if available
		$location = tribe_get_venue( $event_post->ID );
		if ( ! empty( $location ) ) {
			$str_location = html_entity_decode( $location, ENT_QUOTES );
	
			$item[ 'LOCATION' ]=  $str_location;
		}
	
		if ( class_exists( 'Tribe__Events__Pro__Geo_Loc' ) ) {
			$long = Tribe__Events__Pro__Geo_Loc::instance()->get_lng_for_event( $event_post->ID );
			$lat  = Tribe__Events__Pro__Geo_Loc::instance()->get_lat_for_event( $event_post->ID );
			if ( ! empty( $long ) && ! empty( $lat ) ) {
				$item["GEO"] = sprintf( '%s,%s', $lat, $long );
			}
		}

		// add categories if available
		$event_cats = (array) wp_get_object_terms( $event_post->ID, Tribe__Events__Main::TAXONOMY, array( 'fields' => 'names' ) );
		if ( ! empty( $event_cats ) ) {
			$item['CATEGORIES'] = html_entity_decode( join( ',', $event_cats ), ENT_QUOTES );
		}

      		$ical [$item["UID"]]= chrisvf_munge_ical_event( $item );
	}

	return $ical;
}


#TODO BETTERER
function chrisvf_munge_ical_event( $event ) {
  $event["UID"] = preg_replace( "/@.*$/", "", $event["UID"] );
  if( empty($event["LOCATION"] )) { $event["LOCATION"] = "Ventnor"; }
  #if( preg_match( '/PO38 1QS/', $event["LOCATION"] )) { $event["LOCATION"] = "35 Maderia Road"; $event["LOCID"] = 1; }
  #if( preg_match( '/PO38 1RG/', $event["LOCATION"] )) { $event["LOCATION"] = "Bonchurch Old Church"; $event["LOCID"] = 2; }
  #if( preg_match( '/PO38 1LB/', $event["LOCATION"] )) { $event["LOCATION"] = "Parkside"; $event["LOCID"] = 3; }
  #if( preg_match( '/PO38 1SJ/', $event["LOCATION"] )) { $event["LOCATION"] = "Pier St. Playhouse"; $event["LOCID"] = 4; }
  #if( preg_match( '/PO38 1SW/', $event["LOCATION"] )) { $event["LOCATION"] = "St. Catherine's Church"; $event["LOCID"] = 5; }
  #if( preg_match( '/PO38 1XX/', $event["LOCATION"] )) { $event["LOCATION"] = "The Book Bus"; $event["LOCID"] = 6; } # what is this?
  #if( preg_match( '/PO38 1XX/', $event["LOCATION"] )) { $event["LOCATION"] = "The Errant Stage"; $event["LOCID"] = 7; } # what is this?
  #if( preg_match( '/Esplanade/', $event["LOCATION"] )) { $event["LOCATION"] = "The Observatory / Plaza"; $event["LOCID"] = 8; }
  #if( preg_match( '/PO38 1DX/', $event["LOCATION"] )) { $event["LOCATION"] = "The Warehouse"; $event["LOCID"] = 9; }
  #if( preg_match( '/PO38 1XX/', $event["LOCATION"] )) { $event["LOCATION"] = "Trinity Church"; $event["LOCID"] = 10; }
  #if( preg_match( '/PO38 1NS/', $event["LOCATION"] )) { $event["LOCATION"] = "Trinity Theatre"; $event["LOCID"] = 11; } #same as church?
  #if( preg_match( '/PO38 1RZ/', $event["LOCATION"] )) { $event["LOCATION"] = "Ventnor Arts Club"; $event["LOCID"] = 12; }
  #if( preg_match( '/PO38 1SZ/', $event["LOCATION"] )) { $event["LOCATION"] = "Ventnor Winter Gardens"; $event["LOCID"] = 13; }
  return $event;
}

function chrisvf_load_ical($ical_file) {
  $lines = file( $ical_file );
  $events = array();
  $in_event = false;
  foreach( $lines as $line ) {

    $line = chop( $line );
    if ( preg_match( '/BEGIN:VEVENT/', $line ) ) {
      $event = array();
      $in_event = true;
      continue;
    }
    $line = chop( $line );
    if ( preg_match( '/END:VEVENT/', $line ) ) {
      $in_event = false;
      
      $events []= chrisvf_munge_ical_event( $event );
      continue;
    }
    if( !$in_event ) {
      continue;
    }
    #print "*$line<br>";
    preg_match( '/^([^:]+):(.*)/', $line, $bits );
    $key = $bits[1];
    $value = $bits[2];
    $key = preg_replace( '/;.*/','',$key );
    $value = preg_replace( "/\\\\(.)/", "$1", $value );
    $value = preg_replace( "/ +,/", "", $value );
    $event[$key] = $value;
  }
  return $events;
}


function chrisvf_load_pois() {
  
  return array();
}

function chrisvf_load_venues() {
  return array();
}





/* GRID */

// get the time_t for start and end of this event
function chrisvf_event_time($event, $min_t=null, $max_t=null) {
  $allTimes = array();

  if( strlen($event["DTSTART"]) == 8 ) { return $allTimes; } # all day events look kak in the grid

  # no loop, one time per event!
  #foreach( $event->field_date["und"] as $date ) {
    $times = array();
    $times['start'] = strtotime( $event["DTSTART"] );

    if( @$event["DTEND"] && $event["DTEND"]!=$event["DTSTART"]) {
      $times['end'] = strtotime($event["DTEND"]);
      $times['est'] = false;
    } else {
      $times['end'] = $times['start']+3600; // guess an hour
      $times['est'] = true;
    }
    
  return $times;

}

function chrisvf_render_grid( $atts = [], $content = null) {
  $h = array();
  if( @$_GET['debug'] ) {
    $h []= "<pre>".htmlspecialchars(print_r(chrisvf_get_info(),true))."</pre>";
  }

  for( $dow=8;$dow<=13;++$dow ) {
    $date = sprintf('2017-08-%02d', $dow );
    $time_t = strtotime( $date );
    if( date( "Y-m-d", time() ) == date( "Y-m-d", $time_t ) ) {
      $h[]="<a name='today'></a>";
    }
    $h[]="<h2 class='vf_grid_day_heading' style='margin-bottom:0'>".date( "l j F", $time_t )."</h2>";
    #$h[]="<div style='margin-bottom:1em'>&lt;&lt; <a style='font-family:sans-serif; color: black' href='/vfringe/'>back to main site</a></div>";
    $h[]=chrisvf_serve_grid_day( $date );
  }
  $h []= "
<script>
jQuery(document).ready(function() {
  jQuery('.vf_grid_event[data-url]').click( function() {
    var d = jQuery( this );
    window.open( d.attr( 'data-url' ));
  }).css( 'cursor','pointer' );

  jQuery('.vf_grid_itinerary .vf_grid_star').text( '★' );
  jQuery('.vf_grid_event').mouseenter( function() {
    var ev = jQuery( this );
    if( ev.hasClass( 'vf_grid_itinerary' ) ) {
      // no action
    } else {
      jQuery( '.vf_grid_star', this ).text( '☆' );
    }
  });
  jQuery('.vf_grid_event').mouseleave( function() {
    var ev = jQuery( this );
    if( ev.hasClass( 'vf_grid_itinerary' ) ) {
      // no action
    } else {
      jQuery( '.vf_grid_star', this ).text( '' );
    }
  });
  jQuery('.vf_grid_star').mouseenter( function() {
    var stars = jQuery(this);
    if( stars.parent().parent().hasClass( 'vf_grid_itinerary' ) ) {
      stars.text( '☆' );
    } else {
      stars.text( '★' );
    }
  } );
  jQuery('.vf_grid_star').mouseleave( function() {
    var stars = jQuery(this);
    if( stars.parent().parent().hasClass( 'vf_grid_itinerary' ) ) {
      stars.text( '★' );
    } else {
      stars.text( '☆' );
    }
  } );
  jQuery('.vf_grid_star').click( function() {
    var stars = jQuery(this);
    var code = stars.parent().parent().attr( 'data-code' );
    if( stars.parent().parent().hasClass( 'vf_grid_itinerary' ) ) {
      stars.parent().parent().removeClass( 'vf_grid_itinerary' );
      vfItineraryRemove( code );
    } else {
      stars.parent().parent().addClass( 'vf_grid_itinerary' );
      vfItineraryAdd( code );
    }
    return false;
  } );
    
});
</script>";
  return join( "", $h );
}


function chrisvf_cmp_venue($a,$b) {
  global $vf_venues;
  $va = $vf_venues[$a];
  $vb = $vf_venues[$b];
  if( @$va->field_venue_sort_code['und'][0]['value'] > @$vb->field_venue_sort_code['und'][0]['value'] ) { return -1; }
  if( @$va->field_venue_sort_code['und'][0]['value'] < @$vb->field_venue_sort_code['und'][0]['value'] ) { return 1; }
  if( $va->name > $vb->name ) { return 1; }
  if( $va->name < $vb->name ) { return -1; }
  return 0;
}

function chrisvf_serve_grid_day( $date ) {
  // load venues
  global $vf_venues;
  $vf_venues = chrisvf_load_venues();
  $day_start = "08:00:00 BST";
  $day_end = "02:00:00 BST";

  $start_t = strtotime( "$date $day_start" );
  $end_t = strtotime( "$date $day_end" )+60*60*24;

  $start = gmdate("Y-m-d H:i", $start_t );
  $end = gmdate("Y-m-d H:i", $end_t );

  // load events
  $events = chrisvf_get_events(); // add day filter etc.
  if( !$events ) {
    return "<p>No events</p>";
  }

  // work out timeslots
  $times = array();
  foreach( $events as $event ) {
    $ev_time = chrisvf_event_time($event);
#print_r( $ev_times );
#print "<HR>";
    if( $ev_time['start'] >= $end_t ) { continue; } // starts after our window
    if( $ev_time['end'] <= $start_t ) { continue; } // ends before our window
    if( $ev_time['start'] < $start_t ) { $ev_time['start'] = $start_t; }
    if( $ev_time['end']>$end_t ) { $ev_time['end'] = $end_t; }
    $times[$ev_time['start']] = true;
    $times[$ev_time['end']] = true;
  }

  # assumes start_t is on the hour!?!
  for( $t=$start_t; $t<=$end_t; $t+=3600 ) {
    $times[$t] = true;
  }

  ksort($times);
  $times = array_keys( $times );

  $timeslots = array();
  $timemap = array();
  for($i=0;$i<sizeof($times);++$i) {
    if( $i<sizeof($times)-1 ) {
      # the last time isn't a timeslot but it still has an index
      $timeslots []= array( "start"=>$times[$i], "end"=>$times[$i+1] );
    }
    $timemap[ $times[$i] ] = $i;
  }


  // build up grid  
  $grid = array(); # venue=>list of columns for venu
  foreach( $events as $event ) {
    $ev_time =chrisvf_event_time($event);
    $venue_id = $event["LOCATION"];


      if( $ev_time['start'] >= $end_t ) { continue; } // starts after our window
      if( $ev_time['end'] <= $start_t ) { continue; } // ends before our window
      if( $ev_time['start'] < $start_t ) { $ev_time['start'] = $start_t; }
      if( $ev_time['end']>$end_t ) { $ev_time['end'] = $end_t; }

      $start_i = $timemap[$ev_time['start']];
      $end_i = $timemap[$ev_time['end']];

      $column_id = null;
      if( !@$grid[$venue_id] ) {
        # no columns. Leave column_id null and init a place to put columns
        $grid[$venue_id] = array();
      } else {
        # find a column with space, if any
        for( $c=0;$c<sizeof($grid[$venue_id]);++$c ) {
          // check all the slots this event needs
          for($p=$start_i;$p<$end_i;++$p ) {
            if( $grid[$venue_id][$c][$p]['used'] ) {
              continue(2); // skip to next column
            }
          }
          // ok looks like this column is clear!
          $column_id = $c;
          break;
        }
      }
      if( $column_id === null ) {
        $col = array();
        for($p=0;$p<sizeof($timeslots);++$p) {
          $col[$p] = array( "used"=>false );
        }
        $grid[$venue_id][] = $col;
        $column_id = sizeof($grid[$venue_id])-1;
      }
  
      // ok. column_id is now a real column and has space
      // fill out the things as used
      for( $p=$start_i; $p<$end_i; ++$p ) {
        $grid[$venue_id][$column_id][$p]["used"] = true;
      }
      // then put this event in the top one.
      $grid[$venue_id][$column_id][$start_i]["event"] = $event;
      $grid[$venue_id][$column_id][$start_i]["start_i"] = $start_i;
      $grid[$venue_id][$column_id][$start_i]["end_i"] = $end_i;
      $grid[$venue_id][$column_id][$start_i]["width"] = 1;
      $grid[$venue_id][$column_id][$start_i]["est"] = $ev_time['est'];
      $grid[$venue_id][$column_id][$start_i]["code"] = preg_replace( '/@.*/', '',  $event["UID"] );
  } // end of events loop
#print "<pre>"; print_r( $grid ); print "</pre>";

  // venue ids. Could/should sort this later
  $venue_ids = array_keys( $grid );
  usort( $venue_ids, "chrisvf_cmp_venue" );

  // see if we can expand any events to fill the space available.
  foreach( $venue_ids as $venue_id ) {
    $cols = $grid[$venue_id];
    // look at columns except the last one...
    for( $c1=0;$c1<sizeof($cols)-1;++$c1 ) {
      for( $slot1=0;$slot1<sizeof($cols[$c1]);++$slot1 ) {

        // only try to expand actual events
        if( !@$cols[$c1][$slot1]['event'] ) { continue; }

        // try to add this event to additional columns
        for($c2=$c1+1;$c2<sizeof($cols);++$c2) {  // loop of remaining columns
          for( $slot2=$slot1;$slot2<$cols[$c1][$slot1]['end_i'];$slot2++ ) {
            if( $cols[$c2][$slot2]["used"] ) { break(2); }
          }
          // OK, this column gap is free. set it to used and widen the event
          for( $slot2=$slot1;$slot2<$cols[$c1][$slot1]['end_i'];$slot2++ ) {
            $grid[$venue_id][$c2][$slot2]["used"]=true;
          }
          $grid[$venue_id][$c1][$slot1]['width']++;
          // ok.. loop back to try any remaining columns

        } // break(2) exits here go to next event
      }
    } 
  }

  $itinerary = chrisvf_get_itinerary();

  $h = array();
  $h[]= "<div class='vf_grid_outer'>";
  $h[]= "<table class='vf_grid'>";

  // Venue headings
  $h[]= "<tr>";
  $h[]= "<th></th>";
  foreach( $venue_ids as $venue_id ) {
    $cols = $grid[$venue_id];
    $h[]= "<th class='vf_grid_venue' colspan='".sizeof( $cols )."'>";
    $h[]= $venue_id;
    $h[]= "</th>\n";
  }
  $h[]= "<th></th>";
  $h[]= "</tr>\n";

  $odd_row = true;
  foreach( $timeslots as $p=>$slot ) { 
    $hour = date("H",$slot["start"]);
    $row_classes = "";
    if( $odd_row ) {
      $row_classes.= " vf_grid_row_odd";
    } else {
      $row_classes.= " vf_grid_row_even";
    }
    if( $hour % 2 ) {
      $row_classes.= " vf_grid_row_hour_odd";
    } else {
      $row_classes.= " vf_grid_row_hour_even";
    }
    $h[]= "<tr class='$row_classes'>";
    $odd_row = !$odd_row;
    $h[]= "<th class='vf_grid_timeslot'>".date("H:i",$slot["start"])."</th>";
    #$h[]= "<th class='vf_grid_timeslot'>".date("d H:i",$slot["end"])."</th>";
    $odd_col = true;
    foreach( $venue_ids as $venue_id ) {

      for( $col_id=0; $col_id<sizeof($grid[$venue_id]); ++$col_id ) {
        $col = $grid[$venue_id][$col_id];
        $cell = $col[$p];

        if( $odd_col ) {
          $classes = "vf_grid_col_odd";
        } else {
          $classes = "vf_grid_col_even";
        }
        if( $col_id==sizeof($grid[$venue_id])-1 ) {
          $classes .= " vf_grid_col_vlast"; // last column for this venue
        }
        $classes.= " vf_grid_venue_".preg_replace( "/[^a-z]/i", "", $vf_venues[$venue_id]->name );

        if( @$cell['event'] ) {
          $url= $cell["event"]["URL"];
          $height = $cell['end_i'] - $cell['start_i'];
          $classes.= ' vf_grid_event';

          if( @$itinerary['events'][$cell['code']] ) {
            $classes .= " vf_grid_itinerary";
          }
##$a .= "<li>".$cell['code']." , $code";
 #               $classes .= " vf_grid_clash";
 #               $clash = true;
  #            }
   #         }
    
          if( $cell['est'] ) {
            $classes.=' vf_grid_event_noend';
          }
          $id = "g".preg_replace( '/-/','_',$cell['event']['UID'] );
          $data = 
          $h[]= "<td id='$id' data-code='".$cell['event']['UID']."' class='$classes' colspan='".$cell['width']."' rowspan='$height' ".(empty($url)?"":"data-url='".$url."'").">";
          if( $t1["start"]<=time() && $t1["end"]>=time() ) {
            $h[]="<div class='vf_grid_now'>NOW</div>";
          }
          $h[]= "<div class='vf_grid_event_middle'>";
          $h[]= "<div class='vf_grid_star'>";
#          $h[]= "<div class='vf_grid_star_off' title='Add to your itinerary'><span class='vf_nhov'>☆</span><span class='vf_hov'>★</span></div>";
#          $h[]= "<div class='vf_grid_star_on' title='Remove from itinerary'><span class='vf_hov'>☆</span><span class='vf_nhov'>★</span></div>";
          $h[]= "</div>";
          $h []= "<div class='vf_grid_inner'>";

          $h[]= "<div class='vf_grid_cell_title'>". $cell['event']["SUMMARY"]."</div>";
          if( !empty( trim( $cell['event']['CATEGORIES'] ) ) ) { 
            foreach( preg_split( "/,/", $cell['event']['CATEGORIES'] ) as $cat ) {
              $h[]= "<div class='vf_grid_cat'>".$cat."</div>";
            }
          }

#          if( $clash ) { 
#            $h[]= "<div style='font-style:italic;font-size:80%;margin-top:1em'>Clashes with your itinerary</div>";
#          }
          if( $cell['est'] ) {
            $h[]= "<div>[End time not yet known]</div>";
          }
#          $h[]= "<hr />".$vf_venues[$venue_id]->name.",$col_id";
#          $h[]= ",".$cell['width'];
#          $h[]= ",".$cell['start_i'];
#          $h[]= ",".$cell['end_i'];
          $h[]= "</div>"; # event inner
          $h[]= "</div>"; # event middle
          $h[]= "</td>";
        } else if( $cell["used"] ) {
          $h[]= "";
        } else {
          foreach( $itinerary['events'] as $code=>$i_event ) {
            $t2 = chrisvf_event_time($i_event );
            if( $slot['start']<$t2['end'] && $slot['end']>$t2['start'] ) { 
              $classes .= " vf_grid_busy";
            }
          }


          $h[]= "<td class='$classes vf_grid_freecell'>";
#          $h[]= "<hr />".$vf_venues[$venue_id]->name.",$col_id";
          $h[]= "</td>";
        }
      }
      $odd_col = !$odd_col;
    }
    $h[]= "<th class='vf_grid_timeslot'>".date("H:i",$slot["start"])."</th>";
    $h[]= "</tr>\n";
  }

  // Venue headings
  $h[]= "<tr>";
  $h[]= "<th></th>";
  foreach( $venue_ids as $venue_id ) {
    $cols = $grid[$venue_id];
    $h[]= "<th class='vf_grid_venue' colspan='".sizeof( $cols )."'>";
    $h[]= $venue_id;
    $h[]= "</th>\n";
  }
  $h[]= "<th></th>";
  $h[]= "</tr>\n";

  $h[]= "</table>";
  $h[]= "</div>";
  return join( "", $h );
}


function chrisvf_render_random( $atts = [], $content = null) {
  $events = chrisvf_get_events();
  shuffle( $events );
  $h = "";
  $r=0;
  $h.= "<div style='margin-top:1em;text-align:center'><button id='rbutton'>STOP</button></div>";
  $h.= "<div style='height:500px; padding: 1em; text-align:centre'>";
  foreach( $events as $event ) {
   $time_t = strtotime($event["DTSTART"]);
   ++$r;
   $h .="<div class='rcell' id='r$r' style='".($r==1?"":"display:none;")." text-align:center;'>";
   $h .= "<div style='font-size:150%'>";
   $h .=$event["SUMMARY"];
   $h .= " @ ";
   $h .=$event["LOCATION"];
   $h .= " <br/> ";
   $time_t = strtotime($event["DTSTART"]);
   $h .= date("l jS",$time_t);
   $h .= " - ";
   $h .= date("H:i",$time_t);
   $h .="</div>";
   $code = $event["UID"];
   if( !empty( $event["URL"] ) ) {
     $h .= "<a href='".$event["URL"]."' class='vf_itinerary_button'>More information</a>";
   }
   $h.= "<div class='vf_itinerary_toggle' data-code='$code'></div>";
   $h .="</div>";
  }
  $h .="</div>";

  $h .= "<script>
jQuery(document).ready( function() {
  var maxr=$r;
  var r = 1;
  var rrunning = true;
  setInterval( nextr, 50 );
  function nextr() {
    if( !rrunning ) { return; }
    r++;
    if( r>maxr ) { r=1; }
    jQuery( '.rcell' ).hide();
    jQuery( '#r'+r ).show();
  }
  jQuery( '#rbutton' ).click( function() {
    if( rrunning ) {
      jQuery( '#rbutton' ).text( 'START' );
      rrunning = false; 
    } else {
      jQuery( '#rbutton' ).text( 'STOP' );
      rrunning = true; 
    }    
  });
});
jQuery(document).ready(vfItineraryInit);
</script>
<style>
</style>
";
   //print_r( $events );
  return $h;
}


/* ITINERY */

function chrisvf_print_itinerary_add( $atts = [], $content = null) {
  global $wp_query;
  $code = $wp_query->post->ID."-".tribe_get_start_date( $wp_query->post->ID, false, 'U' );
  print "<div class='vf_itinerary_toggle' data-code='$code'></div>";
  print "<a href='/itinerary' class='vf_itinerary_button'>View itinerary</a>";
  print "<script>jQuery(document).ready(vfItineraryInit);</script>";
}

function chrisvf_render_itinerary_slug( $atts = [], $content = null) {
  $itinerary = chrisvf_get_itinerary();
  $size = count($itinerary["codes"]);
  $style = "";
  if( $size == 0 ) {
   # $style = "display:none";
    $it_count = "";
  } elseif( $size == 1 ) {
    $it_count = "1 event in your itinerary.";
  } else {
    $it_count = "$size events in your itinerary.";
  }

#  $cache = cache_get('chrisvf_now_and_next');
#  if( $cache && $cache->expire > time() ) {
#    $nownext = $cache->data;
#  } else {
#    $nownext = chrisvf_now_and_next();
#    cache_set('chrisvf_now_and_next', $nownext, 'cache', time()+60*5); // cache these for 5 minutes
#  }

  $slug = "
<div class='vf_fred'>
  <div class='vf_itinerary_bar'>
    <div class='vf_itinerary_display' style='$style'><div class='vf_itinerary_count'>$it_count</div><a href='/vfringe/itinerary' class='view_itinerary vf_itinerary_button'>View itinerary</a></div>
    <div class='vf_itinerary_bar_links' style='display:inline-block'><a href='/vfringe/map' class='vf_itinerary_button'>Festival Map</a><a href='/vfringe/planner#today' class='vf_itinerary_button'>Festival Planner</a></div>
  </div>
</div>
";
#<div class='vf_badger' style='min-height: 90px'>$nownext</div>
  return $slug;
}


function chrisvf_now_and_next() {

  // load events
#  $query = new EntityFieldQuery();
#  $entities = $query->entityCondition('entity_type', 'node')
#                 ->addTag('efq_debug')
#                 ->entityCondition('bundle','event' )
#                 ->propertyCondition( 'status', 1 )
#                 ->fieldCondition( 'field_event_classification', 'value', array( 'vFringe','Festival' ) ,"IN" )
#                 ->fieldCondition('field_date','value2',date( "Y-m-d" ),'>=' )
#                 ->execute();
#  @$events = entity_load('node',array_keys($entities['node']));
   
  $entities = array();

  $venues= chrisvf_load_venues();

  $list = array();
  foreach( $events as $event ) {
    foreach( $event->field_date['und'] as $date ) {
        
      $start = $date["value"]." ".$date["timezone_db"]; 
      $time_t = strtotime( $start );
      $end = $date["value2"]." ".$date["timezone_db"]; 
      $end_t = strtotime( $end );
      if( $end_t < time() ) { continue; } # skip done events

      $tid = $event->field_venue['und'][0]['tid'];

      $free = false;
      if( @$event->field_promo['und'] ) {
        foreach( $event->field_promo['und'] as $value ) {
          if( $value['tid'] == 17 || $value['tid'] == 212 ) { $free = true; }
        }
      }

      $venue = $venues[$event->field_venue['und'][0]['tid']];
      if( $time_t>time() && $time_t<time()+90*60 ) { 
        #starts in the next 90 minutes
        $list[]= "<div>".date( "ga",$time_t)." - <strong><a href='".url('node/'. $event->nid)."'>". htmlspecialchars( $event->title, ENT_QUOTES ) ."</strong></a> - <a href='".url('taxonomy/term/'. $venue->tid)."'>".$venue->name."</a></a></div>";
      }
      if( $time_t<time() && $end_t>time()+10*60 && $free ) {  # free, 
        #starts in the next 90 minutes
        $list[]= "<div>Now - <strong><a href='".url('node/'. $event->nid)."'>". htmlspecialchars( $event->title, ENT_QUOTES )."</strong></a> - <a href='".url('taxonomy/term/'. $venue->tid)."'>".$venue->name."</a></div>" ;
      }
    }
  }
  $h = "";
  $slides = array(array());
  $PER_SLIDE = 3;
  foreach( $list as $text ) {
    if( sizeof( $slides[sizeof($slides)-1] ) >= $PER_SLIDE ) {
      array_push( $slides, array() );
    }
    $slides[sizeof($slides)-1] []= $text;
  }
  $path = drupal_get_path('module', 'chrisvf_extras');


  $h= "<div class='cycleslideshow' style='font-size:70%'>";
  foreach( $slides as $slide ) {
    $h .= "<div class='nownext_slide'>".join( "", $slide )."</div>";
  }
  $h .= "</div>"; 
  $h .= '<script src="/'.$path.'/jquery.cycle.lite.js"></script>';
  $h .= "<script>
jQuery(document).ready(function(){
  jQuery('.cycleslideshow').cycle({ fx:    'fade', speed:  300, timeout: 3500 });
});
</script>";
  return $h;
}

function chrisvf_get_itinerary($ids=null) {

  global $chrisvf_itinerary;

  if( !isset( $chrisvf_itinerary ) ) {
    $chrisvf_itinerary = array();
    if( @$_COOKIE["itinerary"] ) {
      $chrisvf_itinerary["codes"] = preg_split( '/,/', $_COOKIE["itinerary"] );
    } else {
      $chrisvf_itinerary["codes"] = array();
    }
    // get itinerary from cache

    // load events
    $events = chrisvf_get_events();
    $chrisvf_itinerary["events"] = array();
    foreach( $chrisvf_itinerary["codes"] as $code ) {
      $chrisvf_itinerary["events"][$code] = $events[$code];
    }
  }
  return $chrisvf_itinerary;
}
  
function chrisvf_render_itinerary( $atts = [], $content = null) {
  $itinerary = chrisvf_get_itinerary();
  #$venues= chrisvf_load_venues();

  $h = array();
  $list = array();
  $script = array();
  #$h []= "<h1>Your Ventnor Fringe and Festival Itinerary</h1>";
  $h []= "<p>This list is saved on your browser using a cookie.</p>";
  if( count($itinerary['codes']) ) {
    $h[]= "<p style='display:none' ";
  } else {
    $h[]= "<p ";
  }
  $h []= "class='vf_itinerary_none'>No items in your itinerary. Browse the website and add some.</p>";
  if( count($itinerary['codes']) ) {
    $h []= chrisvf_render_itinerary_table( $itinerary );
    $link = "http://vfringe.ventnorexchange.co.uk/saved-itinerary?ids=".urlencode( $_COOKIE["itinerary"] );
    $msg = "My #VFringe plan: $link";
    $h []= "<div>";
    $h []= "<a href='http://twitter.com/intent/tweet?text=".urlencode($msg)."' class='vf_itinerary_button'>Tweet my Itinerary</a>";
    $h []= "<a href='https://www.facebook.com/sharer/sharer.php?u=".urlencode($link)."' class='vf_itinerary_button'>Post to Facebook</a>";
    $h []= "</div>";
  }
  return join( "", $h) ;
}

function chrisvf_render_saved_itinerary( $atts = [], $content = null) {
  $itinerary = array();
  $itinerary["codes"] = preg_split( '/,/', $_GET['ids'] );
  $events = chrisvf_get_events();
  $itinerary["events"] = array();
  foreach( $itinerary["codes"] as $code ) {
    $itinerary["events"][$code] = $events[$code];
  }
  $h = "";
  if( !empty( $_GET['title'] ) ) {
    $h .= "<h2>".htmlspecialchars(preg_replace('/\\\\(.)/','$1', $_GET['title'] ))."</h2>";
  } 
  $h .= chrisvf_render_itinerary_table( $itinerary, false );
  return $h;
}

function chrisvf_render_itinerary_table( $itinerary, $active = true ) {
  $h = array(); 
  $h []="<table class='vf_itinerary_table'>";

  $h []="<tr>";
  $h []="<th>Date</th>";
  $h []="<th>Start</th>";
  $h []="<th>End</th>";
  $h []="<th>Event</th>";
  $h []="<th>Venue</th>";
  if( $active ) { $h []="<th>Actions</th>"; }
  $h []="</tr>";

  foreach( $itinerary['codes'] as $code ) {
    $event = @$itinerary['events'][$code];
    if( !$event ) {
      $time_t = 0;
    } else {
      $time_t = strtotime($event["DTSTART"]);
    }
    if( @!is_array( $list[$time_t] ) ) { $list[$time_t][]=$code; }
  }  
  ksort( $list );
  global $vf_js_id;
  foreach( $list as $start_time=>$codes ) {
    foreach( $codes as $code ) {
      ++$vf_js_id;
      $event = @$itinerary['events'][$code];
      $h []= "<tr id='${vf_js_id}_row'>";    
      if( $event ) {
        $h []= "<td>".date("l jS F",$start_time)."</td>";
        $h []= "<td>".date("H:i",$start_time)."</td>";
        if( @$event["DTEND"] ) {
          $end_t = strtotime($event["DTEND"]);
          $h []= "<td>".date("H:i",$end_t)."</td>";
        } else { 
          $h []= "<td></td>";
        }

        if( empty( $event["URL"] ) ) {
          $h []= "<td>".$event["SUMMARY"]."</td>";
        } else {
          $h []= "<td><a href='".$event["URL"]."'>".$event["SUMMARY"]."</a></td>";
        }
        #$venue = $venues[$event->field_venue['und'][0]['tid']];
	$h []= "<td>". $event["LOCATION"]."</td>";
        #$h []= "<td><a href='".url('taxonomy/term/'. $venue->tid)."'>".$venue->name."</a></td>";
  
      } else {
        $h []= "<td></td>";
        $h []= "<td></td>";
        $h []= "<td></td>";
        $h []= "<td></td>"; 
        $h []= "<td>Error, event missing (may have been erased or altered. Sorry.)</td>";
      }
      if( $active ) { $h []= "<td><div class='vf_itinerary_button vf_itinerary_remove_button' id='${vf_js_id}_remove'>Remove from itinerary</div>"; }
      $h []= "</tr>";
      $script []= "jQuery( '#${vf_js_id}_remove' ).click(function(){ jQuery( '#${vf_js_id}_row' ).hide(); vfItineraryRemove( '".$code."' ) });\n";
    }
  }
  $h []= "</table>";

  $h []= "<script>jQuery(document).ready(function(){\n".join( "", $script )."});</script>";
  return join( "", $h) ;
}


/* MAP */


function chrisvf_serve_map() {
  $venues= chrisvf_load_venues();
  $pois= chrisvf_load_pois();
  $places = array_merge( $venues, $pois);

  $year2016 = 1451606400;


  // load events
  $query = new EntityFieldQuery();
  $entities = $query->entityCondition('entity_type', 'node')
                 ->addTag('efq_debug')
                 ->entityCondition('bundle','event' )
                 ->propertyCondition( 'status', 1 )
                 ->fieldCondition( 'field_event_classification', 'value', array( 'vFringe','Festival' ) ,"IN" )
                 ->fieldCondition('field_date','value',$year2016,'>' )
                 ->execute();
  @$events = entity_load('node',array_keys($entities['node']));

  $venueEvents = array();
  $nowFree = array();
  $soon = array();
  foreach( $events as $event ) {
    foreach( $event->field_date['und'] as $date ) {
        
      $start = $date["value"]." ".$date["timezone_db"]; 
      $time_t = strtotime( $start );
      $end = $date["value2"]." ".$date["timezone_db"]; 
      $end_t = strtotime( $end );
      if( $end_t < time() ) { continue; } # skip done events

      $date = date( "Y-m-d", $time_t );
      $dateLabel = date( "l jS F", $time_t );
      $time = date( "H:i", $time_t );
      $tid = $event->field_venue['und'][0]['tid'];

      $free = false;
      if( @$event->field_promo['und'] ) {
        foreach( $event->field_promo['und'] as $value ) {
          if( $value['tid'] == 17 || $value['tid'] == 212 ) { $free = true; }
        }
      }

      @$venueEvents[$tid][$date]['label'] = $dateLabel;
      @$venueEvents[$tid][$date]['times'][$time][]=$event;

      if( $time_t>time() && $time_t<time()+90*60 ) { 
        #starts in the next 90 minutes
        $soon[$tid][]= "<div><strong>".date( "ga",$time_t)." - ". htmlspecialchars( $event->title, ENT_QUOTES ) ."</strong></div>";
      }
      if( $time_t<time() && $end_t>time()+10*60 && $free ) {  # free, 
        #starts in the next 90 minutes
        $nowFree[$tid][]= "<div><strong>Now - ". htmlspecialchars(  $event->title,  ENT_QUOTES )."</strong></div>" ;
      }
    }
  }
?>
<html>
<meta charset="UTF-8" />
<link href='http://fonts.googleapis.com/css?family=Montserrat:400,700' rel='stylesheet' type='text/css' />
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
<style>
html, body {
  margin: 0;
  color: #444;
  font-family: "MontserratRegular";
}
</style>
<?php
  $path = drupal_get_path('module', 'chrisvf_extras');
  print '<link rel="stylesheet" href="/'.$path.'/leaflet.css" />';
  print '<link rel="stylesheet" href="/'.$path.'/leaflet.label.css" />';
  print '<script src="/'.$path.'/leaflet.js"></script>';
  print '<script src="/'.$path.'/leaflet.label.js"></script>';
  global $mapid;
  $id = "map".(++$mapid); // make sure the js uses a unique ID in case multiple maps on a page
  print "<div id='$id' style='height: 100%; width: 100%;'></div>\n";
  print "<script>\n";
?>
var map;
var bounds = L.latLngBounds([]);
(function(mapid){
  map = L.map(mapid,{scrollWheelZoom: false});
  var icon;
  var marker;
  L.tileLayer('http://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{ attribution: 'Map data &copy; <a href="http://openstreetmap.org">OpenStreetMap</a> contributors, <a href="http://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>', maxZoom: 20 }).addTo(map);
<?php
  print "}('$id'));\n";

?>
  var imageUrl = 'http://ventnorexchange.co.uk/sites/default/files/fringedatesarcpink.png';
  var imageBounds = [ [50.59150, -1.20201], [50.58901, -1.21435] ];
  L.imageOverlay(imageUrl, imageBounds).addTo(map);
<?php


  foreach( $places as $place ) {
    $lat_long = chrisvf_taxonomy_term_single_value($place,'field_lat_long');
    $icon_url = chrisvf_taxonomy_term_single_value($place,'field_icon_url','http://data.southampton.ac.uk/images/numbericon.png?n=X');
    $icon_size = chrisvf_taxonomy_term_single_value($place,'field_icon_size','32,37');
    $icon_anchor = chrisvf_taxonomy_term_single_value($place,'field_icon_anchor','16,37');

    if( !$lat_long ) { continue; }
   //popupAnchor: [0, -40]

    $popup = "<h2>".htmlspecialchars($place->name)."</h2>";
    if( @$venueEvents[$place->tid] ) {
      ksort( $venueEvents[$place->tid] );
      foreach( $venueEvents[$place->tid] as $day ) {
        $popup .= "<h3 style='margin-bottom:3px'>".$day["label"]."</h3>";
        ksort( $day['times'] );
        foreach( $day['times'] as $time=>$events ) {
          foreach( $events as $event ) {
            $free = false;
            if( @$event->field_promo['und'] ) {
              foreach( $event->field_promo['und'] as $value ) {
                if( $value['tid'] == 17 || $value['tid'] == 212 ) { $free = true; }
              }
            }
            
            $url= url("node/".$event->nid);
            $popup .= "<div>$time - <a href='$url'>".htmlspecialchars( $event->title )."</a>".($free?" - Free Fringe":"")."</div>";
          }
        }
      }
    }
    $nowText = "";
    if( @$nowFree[ $place->tid ] ) {
      $nowText .= join( "", $nowFree[ $place->tid ] );
    }
    if( @$soon[ $place->tid ] ) {
      $nowText .= join( "", $soon[ $place->tid ] );
    }
    if( $nowText != "" ) {
      $nowText = "'$nowText'";
    } else {
      $nowText = 'false';
    }
?>
  (function(lat_long,icon_url,icon_size,icon_anchor, name, popupText,nowText){
    icon = L.icon( { iconUrl: icon_url, iconSize: icon_size, iconAnchor: icon_anchor, labelAnchor: [16, -18], popupAnchor: [ 0,-40 ] } );
    var label = "<strong>"+name+"</strong>"; 
    var labelOpts = { noHide: false };
    var markerOpts = { icon:icon };
    markerOpts.riseOnHover = true;
    labelOpts.direction = 'right';
    var popup = L.popup();
    popup.setContent( '<div style="max-height: 300px; overflow:auto">'+popupText+'</div>' );
    var marker = L.marker(lat_long, markerOpts ).bindPopup(popup).addTo(map);
    if( nowText ) {
      marker.bindLabel(nowText, { noHide: true, direction: 'left' } );
    }

    bounds.extend( lat_long );
<?php 
    print "}([$lat_long],'$icon_url',[$icon_size],[$icon_anchor],'".htmlspecialchars($place->name, ENT_QUOTES)."','".preg_replace("/'/","\\'",$popup)."',$nowText));\n";
  }

  print "map.fitBounds( bounds );\n";
  print "</script>\n";
  print "</html>\n";
}

// eat a sea horse

