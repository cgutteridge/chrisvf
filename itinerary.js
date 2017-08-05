var vfNotification;
function vfNotify(msg) {
    if( !vfNotification ) { 
        vfNotification = jQuery("<div class='vf_notification'></div>" );
        jQuery('body').append( vfNotification );
    }
    vfNotification.text( msg );
    vfNotification.show();
    setTimeout( function() {
        vfNotification.animate({opacity: 0}, function() { 
            jQuery(this).hide();
            jQuery(this).css('opacity',0.8);
        }); }, 500 );
}

function vfItineraryAdd(nid) {
  var list = vfGetItinerary();

  list.push( nid ); // code
  vfSetItinerary(list);
  vfUpdateItineraryCount(list.length);
  vfNotify( "Event added to itinerary" );
}

function vfItineraryRemove(nid) {
  var list = vfGetItinerary();
  var newlist = [];
  for( var i=0; i<list.length; ++i ) {
    if( list[i] != nid ) { newlist.push( list[i] ); }
  }
  vfSetItinerary( newlist );
  vfUpdateItineraryCount(newlist.length);
  vfNotify( "Event removed from itinerary" );
}

function vfUpdateItineraryCount(n) {

  if( n==0 ) {
    jQuery('.vf_itinerary_display').hide();
    jQuery('.vf_itinerary_none').show();
  } else if( n==1 ) {
    jQuery('.vf_itinerary_display').show();
    jQuery('.vf_itinerary_count').text( "1 item in your itinerary." );
    jQuery('.vf_itinerary_none').hide();
  } else {
    jQuery('.vf_itinerary_display').show();
    jQuery('.vf_itinerary_count').text( n+" items in your itinerary." );
    jQuery('.vf_itinerary_none').hide();
  } 
}

function vfSetItinerary(list) {
  var name = 'itinerary';
  var value = list.join( "," );
  var days = 100; 
  if (days) {
    var date = new Date();
    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
    expires = "; expires=" + date.toGMTString();
  } else {
    expires = "";
  }
  document.cookie = name + "=" + value + expires + "; path=/";
}

function vfGetItinerary() {
  var c_name = 'itinerary';
  if (document.cookie.length > 0) {
    c_start = document.cookie.indexOf(c_name + "=");
    if (c_start != -1) {
      c_start = c_start + c_name.length + 1;
      c_end = document.cookie.indexOf(";", c_start);
      if (c_end == -1) {
        c_end = document.cookie.length;
      }
      var v = unescape(document.cookie.substring(c_start, c_end));
      var list = [];
      if( v != "" ) { list = v.split( /,/ ); }
      return list;
    }
  }
  return [];
}

var vfItineraryDoneInit = false;

function vfItineraryInit() {
  if( vfItineraryDoneInit ) { return; }
  var vfItineraryDoneInit = true;
  //$itin = vfringe_extras_get_itinerary();
  jQuery('.vf_itinerary_toggle').each(function(){
    var div = jQuery(this);
    var itin = vfGetItinerary();
    var code = div.attr('data-code');
    var in_itin = false;
    for( i=0;i<itin.length;i++ ) { if( itin[i]==code ) { in_itin=true; } }
    // if item is in itinerary
    var add_button = jQuery( "<div class='vf_itinerary_button_add vf_itinerary_button'>Add to itinerary</div>" );
    var remove_button = jQuery( "<div class='vf_itinerary_button_remove vf_itinerary_button'>Remove from itinerary</div>" );
   
    if( in_itin ) {
      add_button.hide();
    } else {
      remove_button.hide();
    }
    div.append( add_button );
    div.append( remove_button );    

    add_button.click(function(){
      var div = jQuery(this).parent();
      var code = div.attr('data-code');
      div.find('.vf_itinerary_button_add').hide();
      div.find('.vf_itinerary_button_remove').css( 'display','inline-block' );
      vfItineraryAdd( code );
    });

    remove_button.click(function(){
      var div = jQuery(this).parent();
      var code = div.attr('data-code');
      div.find('.vf_itinerary_button_add').css( 'display','inline-block' );
      div.find('.vf_itinerary_button_remove').hide();
      vfItineraryRemove( code );
    });

  });

}
