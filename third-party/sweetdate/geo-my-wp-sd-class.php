<?php
/*
  Plugin Name: GMW Add-on - Members Directory
  Plugin URI: http://www.geomywp.com
  Description: Add Geo features to buddypress's members directory
  Author: Eyal Fitoussi
  Version: 1.1.3
  Author URI: http://www.geomywp.com
  Text Domain: GMW-MD
  Domain Path: /languages/
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
    exit;

/**
 * GMW_Members_Directory_Query class.
 */
class GMW_SD_Class_Query {

    /**
     * Constructor
     */
    public function __construct() {

        // Define constants
        define( 'GMW_SD_PATH', untrailingslashit( get_template_directory() . '/gmw/' ) );
        define( 'GMW_SD_URL', untrailingslashit( get_template_directory_uri() . '/gmw/' ) );

        $this->settings = get_option( 'gmw_options' );

        //return if disabled
        if ( !isset( $this->settings['sweet_date']['status'] ) || $this->settings['sweet_date']['status'] != 1 )
            return;

        $this->gmwMD                = ( isset( $this->settings['sweet_date'] ) ) ? $this->settings['sweet_date'] : false;
        $this->gmwMD['your_lat']    = false;
        $this->gmwMD['your_lng']    = false;
        $this->gmwMD['org_address'] = false;
        $this->formData['query']    = false;
        $this->formData['address']  = ( isset( $_GET['field_address'] ) && !empty( $_GET['field_address'] ) ) ? $_GET['field_address'] : false;
        $this->formData['orderby']  = ( isset( $_GET['orderby'] ) && !empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : '';
        $radius                     = str_replace( ' ', '', explode( ',', $this->gmwMD['radius'] ) );
        $this->formData['radius']   = ( isset( $_GET['field_radius'] ) && !empty( $_GET['field_radius'] ) ) ? $_GET['field_radius'] : false;

        $this->clauses = array(
            'bp_user_query' => array( 'where' => false ),
            'wp_user_query' => array( 'query_fields' => false ),
        );

        //action hooks/ filters
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_register_styles' ) );

        add_filter( 'kleo_bp_search_add_data', array( $this, 'members_directory_form' ) );

        add_action( 'bp_members_directory_order_options', array( $this, 'orderby_distance' ), 5 );
        add_action( 'bp_before_members_loop', array( $this, 'members_query' ) );

        add_action( 'bp_pre_user_query', array( $this, 'gmwBpDirectoryQuery' ) );
        add_action( 'pre_user_query', array( $this, 'gmwWpDirectoryQuery' ) );

        add_action( 'bp_after_directory_members_list', array( $this, 'trigger_js_and_maps' ) );
        add_action( 'bp_members_inside_avatar', array( $this, 'get_distance' ) );

        add_action( 'bp_directory_members_item', array( $this, 'add_elements_to_results' ) );

        if ( isset( $this->gmwMD['map_use'] ) )
            add_action( 'bp_before_directory_members_list', array( $this, 'main_map' ) );

    }

    public function frontend_register_styles() {

        $screenLoader = ( isset( $this->gmwMD['screen_loader'] ) ) ? $this->gmwMD['screen_loader'] : false;

        wp_enqueue_style( 'gmw-sd-style', GMW_SD_URL . '/assets/css/style.css' );

        if ( isset( $this->gmwMD['map_use'] ) ) {
            wp_register_script( 'gmw-sd-map', GMW_SD_URL . '/assets/js/map.js', array( 'jquery' ), GMW_VERSION, true );
            wp_register_script( 'gmw-marker-clusterer', GMW_SD_URL . '/assets/js/marker-clusterer.js', array( 'jquery' ), GMW_VERSION, true );
        }

    }

    public function members_directory_form( $search_form_html ) {

        $radius = str_replace( ' ', '', explode( ',', $this->gmwMD['radius'] ) );

        $search_form_html .= '<label class="two columns">';
        $search_form_html .= '<input type="text" name="field_address" id="gmw-md-address-field" value="' . $this->formData['address'] . '" placeholder="' . __( 'Enter Address...', 'GMW-MD' ) . '" />';
        $search_form_html .= '</label>';

        //Display radius dropdown
        if ( count( $radius ) > 1 ) :

            $radiusText = ( $this->gmwMD['units'] == '6371' ) ? __( ' -- Kilometers -- ', 'GMW-MD' ) : __( ' -- Miles -- ', 'GMW-MD' );

            $search_form_html .= '<div class="two columns">';
            $search_form_html .= '<select class="expand" name="field_radius">';
            $search_form_html .= '<option value="" selected="selected">' . $radiusText . '</option>';
            foreach ( $radius as $value ) :
                $search_form_html .= '<option value="' . $value . '"';
                if ( $value == $this->formData['radius'] ) {
                    $search_form_html .= 'selected="selected"';
                } $search_form_html .= '>' . $value . '</option>';
            endforeach;
            $search_form_html .= '</select>';
            $search_form_html .= '</div>';

        //display hidden default value
        else :
            $search_form_html .= '<input type="hidden" id="gmw-md-radius-dropdown" name="gmw_md_radius" value="' . end( $radius ) . '" />';
        endif;

        $orderby = array( 'active', 'newest', 'alphabetical' );

        if ( $this->formData['address'] == true ) {
            array_unshift( $orderby, 'distance' );
        }

        $search_form_html .= '<div class="two columns">';
        $search_form_html .= '<select class="expand" name="orderby">';
        $search_form_html .= '<option value="">' . __( 'Order By', 'GMW' ) . '</option>';

        foreach ( $orderby as $value ) :

            $search_form_html .= '<option value="' . $value . '"';
            if ( $value == $this->formData['orderby'] ) {
                $search_form_html .= 'selected="selected"';
            } $search_form_html .= '>' . ucfirst( $value ) . '</option>';

        endforeach;

        $search_form_html .= '</select>';
        $search_form_html .= '</div>';

        echo $search_form_html;

    }

    /**
     *  Members Query
     */
    function members_query() {
        global $wpdb;

        //set join type based on the query. if no address entered will join all members even if they have no location
        $tJoin  = "RIGHT";
        $tJoin2 = "LEFT";

        //when doing query by address entered
        if ( $this->formData['address'] ) {

            // $this->formData['orderby']  = 'distance';
            //do INNER JOIN. we will show only members with location
            $tJoin  = $tJoin2 = "INNER";

            include_once GMW_PATH . '/includes/geo-my-wp-geocode.php';
            //geocode the address entered
            $this->returned_address = GmwConvertToCoords( $this->formData['address'] );

            //If form submitted and address was not found stop search and display no results
            if ( !isset( $this->returned_address ) || empty( $this->returned_address ) ) {

                $this->formData['query']    = false;
                $this->gmwMD['your_lat']    = false;
                $this->gmwMD['your_lng']    = false;
                $this->gmwMD['org_address'] = 'bad';

                //modify the query to no results
                $this->clauses['bp_user_query']['where']   = ' AND 0 = 1 ';
                $this->clauses['bp_user_query']['orderby'] = ' ORDER BY u.display_name';

                $message = apply_filters( 'gmw_md_no_addrss_found_message', __( 'Sorry, the address was not found. Please try a different address.', 'GMW-MD' ) );
                ?>
                <script>
                    jQuery('#message').html('<p>' + '<?php echo $message; ?>' + '</p>');
                </script>
                <?php
            } else {

                $this->formData['query']    = 'address';
                $this->gmwMD['your_lat']    = $this->returned_address['lat'];
                $this->gmwMD['your_lng']    = $this->returned_address['lng'];
                $this->gmwMD['org_address'] = (!empty( $_POST ) ) ? $_POST['search_terms'] : $this->formData['address'];
            }
        } elseif ( $this->formData['orderby'] == 'distance' ) {
            $this->formData['orderby'] = 'active';
        }

        if ( $this->formData['orderby'] == 'alphabetical' ) {

            if ( !bp_disable_profile_sync() || !bp_is_active( 'xprofile' ) ) {

                $this->clauses['bp_user_query']['select']  = "SELECT DISTINCT u.ID as id , gmwlocations.member_id";
                $this->clauses['bp_user_query']['from']    = " FROM wppl_friends_locator gmwlocations {$tJoin} JOIN {$wpdb->users} u ON gmwlocations.member_id = u.ID";
                $this->clauses['bp_user_query']['where']   = "WHERE u.meta_key = 'last_activity'";
                $this->clauses['bp_user_query']['orderby'] = "ORDER BY u.display_name";
                $this->clauses['bp_user_query']['order']   = "ASC";

                // When profile sync is disabled, alphabetical sorts must happen against
                // the xprofile table
            } else {
                global $bp;

                $fullname_field_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$bp->profile->table_name_fields} WHERE name = %s", bp_xprofile_fullname_field_name() ) );

                $this->clauses['bp_user_query']['select']  = "SELECT DISTINCT u.user_id as id , gmwlocations.member_id";
                $this->clauses['bp_user_query']['from']    = " FROM wppl_friends_locator gmwlocations {$tJoin} JOIN {$bp->profile->table_name_data} u ON gmwlocations.member_id = id";
                $this->clauses['bp_user_query']['where']   = "WHERE u.field_id = {$fullname_field_id} ";
                $this->clauses['bp_user_query']['orderby'] = "ORDER BY u.value";
                $this->clauses['bp_user_query']['order']   = "ASC";
            }
        } elseif ( $this->formData['orderby'] == 'newest' || $this->formData['orderby'] == 'active' || $this->formData['orderby'] == '' ) {

            $this->clauses['bp_user_query']['select'] = "SELECT DISTINCT u.user_id , gmwlocations.member_id";
            $this->clauses['bp_user_query']['from']   = " FROM wppl_friends_locator gmwlocations {$tJoin} JOIN {$wpdb->usermeta} u ON gmwlocations.member_id = u.user_id";
            $this->clauses['bp_user_query']['where']  = "WHERE u.meta_key = 'last_activity'";

            if ( $this->formData['orderby'] == 'newest' ) {
                $this->clauses['bp_user_query']['orderby'] = "ORDER BY u.user_id";
            } else {
                $this->clauses['bp_user_query']['orderby'] = "ORDER BY u.meta_value";
            }

            $this->clauses['bp_user_query']['order'] = "DESC";

            //when order by distance
        } elseif ( $this->formData['orderby'] == 'distance' ) {

            $this->clauses['bp_user_query']['select'] = "SELECT gmwlocations.member_id, u.user_id as id";
            $this->clauses['bp_user_query']['from']   = " FROM wppl_friends_locator gmwlocations INNER JOIN {$wpdb->usermeta} u ON gmwlocations.member_id = u.user_id";
            $this->clauses['bp_user_query']['where']  = "WHERE u.meta_key = 'last_activity'";

            if ( $this->gmwMD['your_lat'] != false ) {

                $this->clauses['bp_user_query']['orderby'] = "ORDER BY distance";
            } else {

                $this->formData['query'] = false;
                $this->gmwMD['your_lat'] = false;
                $this->gmwMD['your_lng'] = false;

                $this->clauses['bp_user_query']['where']   = 'AND 0 = 1';
                $this->clauses['bp_user_query']['orderby'] = 'ORDER BY u.user_id';
                ?>
                <script>
                    jQuery(window).ready(function() {
                        jQuery('#message').html('<p>Sorry, we couldn\'t find the address you enter .</p>');
                    });
                </script>
                <?php
            }

            $this->clauses['bp_user_query']['order'] = "ASC";
        }

        /*
         * if address entered
         * prepare the filter of the select clause of the SQL function. the function join the members table with
         * wppl_friends_locator table, will calculate the distance and will get only the members that
         * within the radius was chosen
         */
        if ( $this->gmwMD['your_lat'] !== false ) {

            $this->clauses['bp_user_query']['select'] .= $wpdb->prepare( " , ROUND( %d * acos( cos( radians( %s ) ) * cos( radians( gmwlocations.lat ) ) * cos( radians( gmwlocations.long ) - radians( %s ) ) + sin( radians( %s ) ) * sin( radians( gmwlocations.lat) ) ),1 ) AS distance ", $this->gmwMD['units'], $this->gmwMD['your_lat'], $this->gmwMD['your_lng'], $this->gmwMD['your_lat'] );
            $this->clauses['bp_user_query']['having']       = $wpdb->prepare( " HAVING distance <= %d OR distance IS NULL", $this->formData['radius'] );
            $this->clauses['wp_user_query']['query_fields'] = $wpdb->prepare( " ,ROUND( %d * acos( cos( radians( %s ) ) * cos( radians( gmwlocations.lat ) ) * cos( radians( gmwlocations.long ) - radians( %s ) ) + sin( radians( %s ) ) * sin( radians( gmwlocations.lat) ) ),1 ) AS distance", $this->gmwMD['units'], $this->gmwMD['your_lat'], $this->gmwMD['your_lng'], $this->gmwMD['your_lat'] );
        }

        //select all fields from location table
        $this->clauses['wp_user_query']['query_fields'] .= " , gmwlocations.*";
        $this->clauses['wp_user_query']['query_from'] = " {$tJoin2} JOIN wppl_friends_locator gmwlocations ON ID = gmwlocations.member_id ";

    }

    public function gmwBpDirectoryQuery( $gmwBpQuery ) {

        $gmwBpQuery->query_vars['count_total'] = 'sql_calc_found_rows';

        $gmwBpQuery->uid_clauses['select'] = $this->clauses['bp_user_query']['select'];
        $gmwBpQuery->uid_clauses['select'] .= $this->clauses['bp_user_query']['from'];
        $gmwBpQuery->uid_clauses['where']  = $this->clauses['bp_user_query']['where'];

        if ( isset( $this->clauses['bp_user_query']['having'] ) ) {
            $gmwBpQuery->uid_clauses['where'] .= $this->clauses['bp_user_query']['having'];
        }

        $gmwBpQuery->uid_clauses['orderby'] = $this->clauses['bp_user_query']['orderby'];
        $gmwBpQuery->uid_clauses['order']   = $this->clauses['bp_user_query']['order'];

        return $gmwBpQuery;

    }

    public function gmwWpDirectoryQuery( $gmwWpQuery ) {

        $gmwWpQuery->query_fields .= $this->clauses['wp_user_query']['query_fields'];
        $gmwWpQuery->query_from .= $this->clauses['wp_user_query']['query_from'];

        return $gmwWpQuery;

    }

    /**
     * Trigger javascript to display maps and markers
     */
    public function trigger_js_and_maps() {
        global $members_template;

        $this->gmwMD['map_icon_usage'] = '';
        $this->gmwMD['page']           = $members_template->pag_page;
        $this->gmwMD['per_page']       = $members_template->pag_num;

        wp_enqueue_script( 'gmw-sd-map' );
        wp_enqueue_script( 'gmw-marker-clusterer' );
        wp_localize_script( 'gmw-sd-map', 'mdMapArgs', $this->gmwMD );

    }

    /**
     * Create Main map
     */
    public function main_map() {

        $mainMap = '';
        $mainMap .= '<div class="gmw-map-wrapper gmw-md-main-map-wrapper" id="gmw-md-main-map-wrapper" style="width:' . $this->gmwMD['map_width'] . ';height:' . $this->gmwMD['map_height'] . '">';
        $mainMap .= '<div class="gmw-map-loader-wrapper gmw-md-loader-wrapper">';
        $mainMap .= '<img class="gmw-map-loader gmw-md-map-loader" src="' . GMW_URL . '/assets/images/map-loader.gif"/>';
        $mainMap .= '</div>';
        $mainMap .= '<div class="gmw-map gmw-md-main-map" id="gmw-md-main-map" style="width:100%; height:100%"></div>';
        $mainMap .= '</div>';

        echo $mainMap;

    }

    /**
     * Get directions" link
     * @version 1.0
     * @author Eyal Fitoussi
     */
    public function get_directions() {
        global $members_template;

        if ( !isset( $members_template->member->distance ) )
            return;

        $units = ( $this->gmwMD['units'] == '6371' ) ? 'ptk' : 'ptm';
        return '<a href="http://maps.google.com/maps?f=d&hl=en&doflg=' . $units . '&geocode=&saddr=' . $this->gmwMD['org_address'] . '&daddr=' . str_replace( " ", "+", $members_template->member->address ) . '&ie=UTF8&z=12" target="_blank">' . __( 'Get Directions', 'GMW-MD' ) . '</a>';

    }

    public function get_distance() {

        //distance
        if ( !isset( $this->gmwMD['distance'] ) || $this->formData['query'] != 'address' )
            return;

        global $members_template;

        if ( !isset( $members_template->member->distance ) )
            return;

        $units = ( $this->gmwMD['units'] == '6371' ) ? __( 'km', 'GMW-MD' ) : __( 'mi', 'GMW-MD' );
        echo '<span "distance">' . $members_template->member->distance . $units . '</span>';

    }

    public function get_address() {
        global $members_template;

        return apply_filters( 'gmw_md_member_address', $members_template->member->address, $members_template->member );

    }

    /**
     * GEM MD Funciton - add GEO my WP elements to members results
     */
    public function add_elements_to_results() {
        global $members_template;

        //add element with member id to be able to scroll to it when marker is clicked
        echo '<div id="gmw-md-member-' . $members_template->member->ID . '"></div>';

        //if member does not have location get out!!
        if ( !isset( $members_template->member->lat ) )
            return;

        //address
        if ( isset( $this->gmwMD['address'] ) )
            echo '<div class="gmw-md-address-wrapper"><span class="gmw-md-address">' . __( 'Address:', 'GMW-MD' ) . '</span> <span class="gmw-md-address-value">' . $this->get_address() . '</span></div>';
        //directions
        if ( isset( $this->gmwMD['directions'] ) )
            echo $this->get_directions();
        //add lat/long locations array to pass to map
        $this->gmwMD['locations'][] = array( 'ID' => $members_template->member->ID, 'lat' => $members_template->member->lat, 'long' => $members_template->member->long );

    }

}
new GMW_SD_Class_Query();
