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
function chrisvf_shortcode($atts = [], $content = null)
{
    // do something to $content
    $content = "FTFW!";

    // always return
    return $content;
}
add_shortcode('chrisvf_test', 'chrisvf_shortcode');

function chrisvf_grid( $atts = [], $content = null) {
    return chrisvf_render_grid();
}
add_shortcode('chrisvf_grid', 'chrisvf_grid');

/* FRINGE FUNCTIONS */

/* LOAD ICAL */

function chrisvf_get_events() {
  $ical_file = "http://vfringe.ventnorexchange.co.uk/whatson/?ical=1";
  $lines = file( $ical_file );
  $events = array();
  $in_event = false;
  foreach( $lines as $line ) {
#<br>*END:VEVENT
#<br>*BEGIN:VEVENT

    $line = chop( $line );
    if ( preg_match( '/BEGIN:VEVENT/', $line ) ) {
      $event = array();
      $in_event = true;
      continue;
    }
    $line = chop( $line );
    if ( preg_match( '/END:VEVENT/', $line ) ) {
      $in_event = false;
#TODO BETTERER
      if( $event["UID"] == "1545-1502272800-1502539200@vfringe.ventnorexchange.co.uk" ) { continue; } # 3 days 
      if( $event["UID"] == "1716-1502064000-1502668799@vfringe.ventnorexchange.co.uk" ) { continue; } # before you start
      if( empty($event["LOCATION"] )) { $event["LOCATION"] = "Ventnor"; }
      $event["LOCATION"] = preg_replace( "/,\s*United Kingdom/","",$event["LOCATION"] );
      $event["LOCATION"] = preg_replace( "/,\s*Ventnor/","",$event["LOCATION"] );
      $event["LOCATION"] = preg_replace( "/,\s*Isle of Wight/","",$event["LOCATION"] );
      $event["LOCATION"] = preg_replace( "/,\s*PO38 ?.../","",$event["LOCATION"] );
      $event["LOCATION"] = preg_replace( "/\s*PO38 ?.../","",$event["LOCATION"] );
      $events []= $event;
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
function chrisvf_event_times($event, $min_t=null, $max_t=null) {
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
    
    $allTimes []= $times;
  #} 

  return $allTimes;
}

function chrisvf_render_grid() {
  $h = array();
  $h []= chrisvf_grid_css();

  for( $dow=8;$dow<=13;++$dow ) {
    $date = sprintf('2017-08-%02d', $dow );
    $time_t = strtotime( $date );
    if( date( "Y-m-d", time() ) == date( "Y-m-d", $time_t ) ) {
      $h[]="<a name='today'></a>";
    }
    $h[]="<h2 style='margin-bottom:0'>".date( "l j F", $time_t )."</h2>";
    $h[]="<div style='margin-bottom:1em'>&lt;&lt; <a style='font-family:sans-serif; color: black' href='/vfringe/'>back to main site</a></div>";
    $h[]=chrisvf_serve_grid_day( $date );
  }
  $h []= "
<script>
jQuery(document).ready(function() {
  jQuery('.vf_grid_event').click( function() {
    var d = jQuery( this );
    window.open( d.attr( 'data-url' ));
  }).css( 'cursor','pointer' );
});
</script>";
  return join( "", $h );
}

function chrisvf_serve_grid() {
  return array( "#markup"=> chrisvf_render_grid() );
}

function chrisvf_serve_grid_raw() {
  $path = drupal_get_path('module', 'chrisvf_extras');
  print '<link rel="stylesheet" href="/'.$path.'/grid.css" />';
  print '<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />';
  print '<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script> ';
  print '<link href="http://fonts.googleapis.com/css?family=Montserrat:400,700" rel="stylesheet" type="text/css" />';

  print chrisvf_render_grid();
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
    $ev_times = chrisvf_event_times($event);
#print_r( $ev_times );
#print "<HR>";
    foreach( $ev_times as $ev_time ) {
      if( $ev_time['start'] >= $end_t ) { continue; } // starts after our window
      if( $ev_time['end'] <= $start_t ) { continue; } // ends before our window
      if( $ev_time['start'] < $start_t ) { $ev_time['start'] = $start_t; }
      if( $ev_time['end']>$end_t ) { $ev_time['end'] = $end_t; }
      $times[$ev_time['start']] = true;
      $times[$ev_time['end']] = true;
    }
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
    $ev_times =chrisvf_event_times($event);
    $venue_id = $event["LOCATION"];

    for( $slot_id = 0; $slot_id<sizeof($ev_times); $slot_id++ ) {
      $ev_time = $ev_times[$slot_id];

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
    }
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
  $h[]= "</tr>";

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

          list($nid,$slot_id) = preg_split( '/:/', $cell['code'] );
          $t1 = chrisvf_event_times($cell['event']);
          $t1 = $t1[$slot_id];
$a="";
          $clash = false;
          if( @$itinerary['events'][$cell['code']] ) {
            $classes .= " vf_grid_itinerary";
          } elseif( @$cell['event']->field_exclude_from_itinerary['und'][0]['value'] ) {
            $classes .= " vf_grid_nonitinerary";
          } else {
            foreach( $itinerary['events'] as $code=>$i_event ) {
              list($nid,$slot_id) = preg_split( '/:/', $code );
              $t2 = chrisvf_event_times($i_event );
              $t2 = $t2[$slot_id];
              if( $t1['start']<$t2['end'] && $t1['end']>$t2['start'] ) { 
#$a .= "<li>".$cell['code']." , $code";
                $classes .= " vf_grid_clash";
                $clash = true;
              }
            }
          }
    
          if( $cell['est'] ) {
            $classes.=' vf_grid_event_noend';
          }
          $h[]= "<td class='$classes' colspan='".$cell['width']."' rowspan='$height' data-url='".$url."'>";
          if( $t1["start"]<=time() && $t1["end"]>=time() ) {
            $h[]="<div class='vf_grid_now'>NOW</div>";
          }
$h[]= $a;
          $h []= "<div class='vf_grid_inner'>";
          if( @$itinerary['events'][$cell['code']] ) {
            $h[]= "<div style='font-style:italic;font-size:80%'>In your itinerary</div>";
            $h[]= "<div style='margin:5px;font-size:200%;color:#000'>★</div>";
          }
          $h[]= $cell['event']["SUMMARY"];
          $h[]= "..".$cell['event']['CATEGORIES'];
#$h[]=print_r( $cell,1 );
          if( $clash ) { 
            $h[]= "<div style='font-style:italic;font-size:80%;margin-top:1em'>Clashes with your itinerary</div>";
          }
          if( $cell['est'] ) {
            $h[]= "<div>[End time not yet known]</div>";
          }
#          $h[]= "<hr />".$vf_venues[$venue_id]->name.",$col_id";
#          $h[]= ",".$cell['width'];
#          $h[]= ",".$cell['start_i'];
#          $h[]= ",".$cell['end_i'];
          $h[]= "</div>";
          $h[]= "</td>";
        } else if( $cell["used"] ) {
          $h[]= "";
        } else {
          foreach( $itinerary['events'] as $code=>$i_event ) {
            list($nid,$slot_id) = preg_split( '/:/', $code );
            $t2 = chrisvf_event_times($i_event );
            $t2 = $t2[$slot_id];
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
    $h[]= "</tr>";
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
  $h[]= "</tr>";

  $h[]= "</table>";
  $h[]= "</div>";
  return join( "", $h );
}


/* ITINERY */

function chrisvf_block_view_itinerary() {
  $itinerary = chrisvf_get_itinerary();
  $size = count($itinerary["codes"]);
  $style = "";
  if( $size == 0 ) {
    $style = "display:none";
    $it_count = "";
  } elseif( $size == 1 ) {
    $it_count = "1 event in your itinerary.";
  } else {
    $it_count = "$size events in your itinerary.";
  }

  $cache = cache_get('chrisvf_now_and_next');
  if( $cache && $cache->expire > time() ) {
    $nownext = $cache->data;
  } else {
    $nownext = chrisvf_now_and_next();
    cache_set('chrisvf_now_and_next', $nownext, 'cache', time()+60*5); // cache these for 5 minutes
  }

  $block = array();    
  $block['subject'] = t('');
  $block['content'] = "
<div class='vf_fred'>
  <div class='vf_itinerary_bar'>
    <div class='vf_itinerary_display' style='$style'><div class='vf_itinerary_count'>$it_count</div><a href='/vfringe/itinerary' class='view_itinerary vf_itinerary_button'>View itinerary</a></div>
    <div class='vf_itinerary_bar_links' style='display:inline-block'><a href='/vfringe/map' class='vf_itinerary_button'>Festival Map</a><a href='/vfringe/planner#today' class='vf_itinerary_button'>Festival Planner</a></div>
  </div>
<div class='vf_badger' style='min-height: 90px'>$nownext</div>
</div>
";
  return $block;
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

function chrisvf_get_itinerary() {

return array("codes"=>array(),"events"=>array()); // CJG NOT DONE YET

  global $chrisvf_itinerary;
  if( !isset( $chrisvf_itinerary ) ) {
    $chrisvf_itinerary = array();
    // get itinerary from cache
    if( @$_COOKIE["itinerary"] ) {
      $chrisvf_itinerary["codes"] = preg_split( '/,/', $_COOKIE["itinerary"] );
    } else {
      $chrisvf_itinerary["codes"] = array();
    }

    $nids = array();
    foreach( $chrisvf_itinerary["codes"] as $code ) {
      list( $nid,$slot) = preg_split( '/:/', $code );
      $nids []= $nid;
    }
    // load events
    // code is just Id for now, but could include start time later...
    $events = entity_load('node',$nids);
    $chrisvf_itinerary["events"] = array();
    foreach( $chrisvf_itinerary["codes"] as $code ) {
      list( $nid,$slot) = preg_split( '/:/', $code );
      $chrisvf_itinerary["events"][$code] = $events[$nid];
    }
  }
  return $chrisvf_itinerary;
}
  
function chrisvf_serve_itinerary() {
  $itinerary = chrisvf_get_itinerary();
  $venues= chrisvf_load_venues();

  $h = array();
  $list = array();
  $script = array();
  $h []= "<h1>Your Ventnor Fringe and Festival Itinerary</h1>";
  $h []= "<p>This list is saved on your browser using a cookie.</p>";
  if( count($itinerary['codes']) ) {
    $h[]= "<p style='display:none' ";
  } else {
    $h[]= "<p ";
  }
  $h []= "class='vf_itinerary_none'>No items in your itinerary. Browse the website and add some.</p>";

  $h []="<table class='vf_itinerary_table'>";

  $h []="<tr>";
  $h []="<th>Date</th>";
  $h []="<th>Start</th>";
  $h []="<th>End</th>";
  $h []="<th>Event</th>";
  $h []="<th>Venue</th>";
  $h []="<th>Actions</th>";
  $h []="</tr>";

  foreach( $itinerary['codes'] as $code ) {
    list( $nid, $slot_id ) = preg_split( '/:/', $code );
    $event = @$itinerary['events'][$code];
    if( !$event ) {
      $time_t = 0;
    } else {
      $time_t = strtotime($event->field_date['und'][$slot_id]['value']." ".$event->field_date['und'][$slot_id]['timezone_db']);
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
        list( $nid, $slot_id ) = preg_split( '/:/', $code );
        $h []= "<td>".date("l jS F",$start_time)."</td>";
        $h []= "<td>".date("H:i",$start_time)."</td>";
        if( @$event->field_date['und'][0]['value2'] ) {
          $end_t = strtotime($event->field_date['und'][$slot_id]['value2']." ".$event->field_date['und'][$slot_id]['timezone_db']);
          $h []= "<td>".date("H:i",$end_t)."</td>";
        } else { 
          $h []= "<td></td>";
        }

        $h []= "<td><a href='".url('node/'. $event->nid)."'>".$event->title."</a></td>";
        $venue = $venues[$event->field_venue['und'][0]['tid']];
        $h []= "<td><a href='".url('taxonomy/term/'. $venue->tid)."'>".$venue->name."</a></td>";
  
      } else {
        $h []= "<td></td>";
        $h []= "<td></td>";
        $h []= "<td></td>";
        $h []= "<td></td>";
        $h []= "<td>Error, event missing (may have been erased or altered. Sorry.)</td>";
      }
      $h []= "<td><div class='vf_itinerary_button vf_itinerary_remove_button' id='${vf_js_id}_remove'>Remove from itinerary</div>";
      $h []= "</tr>";
      $script []= "jQuery( '#${vf_js_id}_remove' ).click(function(){ jQuery( '#${vf_js_id}_row' ).hide(); vfItineraryRemove( '".$code."' ) });\n";
    }
  }
  $h []= "</table>";

  $h []= "<script>jQuery(document).ready(function(){\n".join( "", $script )."});</script>";
  return array( "#markup"=> join( "", $h) );
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

function chrisvf_grid_css() {
  return '
<style>

.vf_grid_outer {
  max-width: 100%;
  overflow: scroll;
}
table.vf_grid th {
  background-color: #eee;
  color: #444;
  border: solid 2px #333;
  padding: 0.2em 0.5em 1em 0.5em;
  vertical-align: top;
}
h2 {
  margin-top: 1em;
  font-family: Helvetica,"MontserratRegular";
}
/*
body { background-color: #000; }
*/

table.vf_grid {
  /*background: url(grass.png) ;*/
  color: #444;
  font-family: Helvetica,"MontserratRegular";
  font-size:80%;
  border-collapse: collapse;
  border-right: solid 2px black;
  border-bottom: solid 2px black;
  page-break-after: always;
}
th.vf_grid_venue {
  vertical-align: bottom;
  padding-top: 1em;
}
.vf_grid_event {
  border: solid 2px #333;
  background-color: #eea;
  vertical-align: top;
  text-align: center;
}
.vf_grid_now {
  background-color: yellow;
}
.vf_grid_inner {
  padding: 0.5em;
}
.vf_grid_col_vlast { 
  /*border-right: dashed 2px #000;*/
}
.vf_grid_freecell { 
  background-color: #eee;
}
.vf_grid_row_hour_even .vf_grid_freecell { 
  background-color: rgba(0,0,0,0.1) !important;
}
.vf_grid_event_noend {
  background: url(saw.png) #ffe repeat-x bottom;
  padding-bottom: 20px !important;
} 

.vf_grid_venue_VentnorBotanicGarden.vf_grid_event,
.vf_grid_venue_Parkside.vf_grid_event {
  background-color: #cfc ;
  color: #040;
}
.vf_grid_freecell {
  /*border-bottom: 1px dashed #cfc;*/
  background: transparent;
}
.vf_grid_venue_ObservatoryBar.vf_grid_event {
  background-color: #ccf ;
  color: #004;
}
/*
.vf_grid_venue_ObservatoryBar.vf_grid_freecell {
  background: url(clouds.png) fixed;
}
*/
.vf_grid_busy {
  background-color: rgba(0,0,0,0.7) !important;
}
.vf_grid_row_hour_even .vf_grid_freecell.vf_grid_busy {
  background-color: rgba(0,0,0,0.6) !important;
}
.vf_grid_itinerary {
  background-color: #fff !important;
  font-size: 150%;
}

.vf_grid_clash {
  background-color: #333 !important;
  color: #ccc !important;
  border-color: #000 !important;
}

.vf_grid_nonitinerary {
  background-color:  #D2B48C !important;
  color: #333 !important;
}
</style>
';
}
