<?php

namespace Frc\GformsCloudExport\Export;

use GFAPI;
use GFCommon;
use GFExport;
use RGFormsModel;
use function absint;
use function check_admin_referer;
use function chr;
use function do_action;
use function esc_attr_e;
use function esc_html;
use function esc_html__;
use function esc_html_e;
use function esc_js;
use function gf_apply_filters;
use function gform_tooltip;
use function gmdate;
use function header;
use function intval;
use function microtime;
use function print_r;
use function rgget;
use function rgpost;
use function sanitize_key;
use function sanitize_title_with_dashes;
use function seems_utf8;
use function str_replace;
use function strlen;
use function strpos;
use function substr;
use function time;
use function uniqid;
use function utf8_encode;
use function wp_create_nonce;
use function wp_die;
use function wp_hash;
use function wp_nonce_field;

/**
 * Class CloudExport
 *
 * Mostly copied from gravityforms/export.php,
 * modified to return file contents and process the whole export in one go.
 *
 * @package Frc\GformsCloudExport\Export
 */
class CloudExport extends GFExport {

    // not modified, needed to call export_lead_page from this class
    public static function export_page() {

        if (!GFCommon::ensure_wp_version()) {
            return;
        }

        echo GFCommon::get_remote_message();

        $view = rgget('view') ? rgget('view') : 'export_entry';

        switch ($view) {

            case 'export_entry':
                self::export_lead_page();
                break;

            case 'import_form' :
                self::import_form_page();
                break;

            case 'export_form' :
                self::export_form_page();
                break;

            default:
                /**
                 * Fires when export pages are gathered
                 *
                 * Used to add additional export settings pages
                 *
                 * @param string $view Set when defining the action string.  Creates the name for the new page
                 */ do_action("gform_export_page_{$view}");
                break;

        }

    }

    // modified to make a post to the ajax url
    public static function export_lead_page() {

        if (!GFCommon::current_user_can_any('gravityforms_export_entries')) {
            wp_die('You do not have permission to access this page');
        }

        self::page_header(__('Export Entries', 'gravityforms'));

        ?>

      <script type="text/javascript">

        var gfSpinner;

        <?php GFCommon::gf_global(); ?>
        <?php GFCommon::gf_vars(); ?>

        function SelectExportForm( formId ) {

          if ( !formId ) return;

          gfSpinner = new gfAjaxSpinner( jQuery( 'select#export_form' ), gf_vars.baseUrl + '/images/spinner.gif', 'position: relative; top: 2px; left: 5px;' );

          var mysack = new sack( "<?php echo admin_url('admin-ajax.php')?>" );
          mysack.execute = 1;
          mysack.method = 'POST';
          mysack.setVar( "action", "rg_select_export_form" );
          mysack.setVar( "rg_select_export_form", "<?php echo wp_create_nonce('rg_select_export_form'); ?>" );
          mysack.setVar( "form_id", formId );
          mysack.onError = function () {
            alert(<?php echo json_encode(__('Ajax error while selecting a form', 'gravityforms')); ?>)
          };
          mysack.runAJAX();

          return true;
        }

        function EndSelectExportForm( aryFields, filterSettings ) {

          gfSpinner.destroy();

          if ( aryFields.length == 0 ) {
            jQuery( "#export_field_container, #export_date_container, #export_submit_container" ).hide()
            return;
          }

          var fieldList = "<li><input id='select_all' type='checkbox' onclick=\"jQuery('.gform_export_field').attr('checked', this.checked); jQuery('#gform_export_check_all').html(this.checked ? '<strong><?php echo esc_js(__('Deselect All', 'gravityforms')); ?></strong>' : '<strong><?php echo esc_js(__('Select All', 'gravityforms')); ?></strong>'); \" onkeypress=\"jQuery('.gform_export_field').attr('checked', this.checked); jQuery('#gform_export_check_all').html(this.checked ? '<strong><?php echo esc_js(__('Deselect All', 'gravityforms')); ?></strong>' : '<strong><?php echo esc_js(__('Select All', 'gravityforms')); ?></strong>'); \"> <label id='gform_export_check_all' for='select_all'><strong><?php esc_html_e('Select All', 'gravityforms') ?></strong></label></li>";
          for ( var i = 0; i < aryFields.length; i++ ) {
            fieldList += "<li><input type='checkbox' id='export_field_" + i + "' name='export_field[]' value='" + aryFields[ i ][ 0 ] + "' class='gform_export_field'> <label for='export_field_" + i + "'>" + aryFields[ i ][ 1 ] + "</label></li>";
          }
          jQuery( "#export_field_list" ).html( fieldList );
          jQuery( "#export_date_start, #export_date_end" )
            .datepicker( {
              dateFormat: 'yy-mm-dd',
              changeMonth: true,
              changeYear: true
            } );

          jQuery( "#export_field_container, #export_filter_container, #export_date_container, #export_submit_container" )
            .hide()
            .show();

          gf_vars.filterAndAny = <?php echo json_encode(esc_html__('Export entries if {0} of the following match:', 'gravityforms')); ?>;
          jQuery( "#export_filters" ).gfFilterUI( filterSettings );

          // add action to form, to make a actual post, not ajax call.
          var data = jQuery( '#gform_export' ).serialize();
          data += '&action=gf_process_export';
          data += '&offset=' + 0;
          data += '&exportId=' + 0;
          jQuery( '#gform_export' ).attr( 'action', ajaxurl + '?' + data );


        }

        (function ( $, window, undefined ) {

          function process( offset, exportId ) {

            if ( typeof offset == 'undefined' ) {
              offset = 0;
            }

            if ( typeof exportId == 'undefined' ) {
              exportId = 0;
            }

            var data = $( '#gform_export' ).serialize();

            data += '&action=gf_process_export';
            data += '&offset=' + offset;
            data += '&exportId=' + exportId;
            $.ajax( {
              type: 'POST',
              url: ajaxurl,
              data: data,
              dataType: 'json'
            } ).done( function ( response ) {
              if ( response.status == 'in_progress' ) {
                $( '#progress_container' ).text( response.progress );
                process( response.offset, response.exportId );
              } else if ( response.status == 'complete' ) {
                $( '#progress_container' ).text( '0%' );
                $( '#please_wait_container' ).hide();
                var formId = parseInt( $( '#export_form' ).val() );
                var url = ajaxurl + '?action=gf_download_export&_wpnonce=<?php echo wp_create_nonce('gform_download_export'); ?>&export-id=' + response.exportId + '&form-id=' + formId;
                $( '#submit_button' ).fadeIn();
                document.location.href = url;

              }
            } );
          }

        }( jQuery, window ));


      </script>

      <p class="textleft"><?php esc_html_e('Select a form below to export entries. Once you have selected a form you may select the fields you would like to export and then define optional filters for field values and the date range. When you click the download button below, Gravity Forms will create a CSV file for you to save to your computer.', 'gravityforms'); ?></p>
      <div class="hr-divider"></div>
      <form id="gform_export" method="post" style="margin-top:10px;">
          <?php echo wp_nonce_field('rg_start_export', 'rg_start_export_nonce'); ?>
        <table class="form-table">
          <tr valign="top">

            <th scope="row">
              <label for="export_form"><?php esc_html_e('Select A Form', 'gravityforms'); ?></label> <?php gform_tooltip('export_select_form') ?>
            </th>
            <td>

              <select id="export_form" name="export_form" onchange="SelectExportForm(jQuery(this).val());">
                <option value=""><?php esc_html_e('Select a form', 'gravityforms'); ?></option>
                  <?php
                  $forms = RGFormsModel::get_forms(null, 'title');

                  /**
                   * Modify list of forms available to export entries from.
                   *
                   * @param array $forms Forms to display on Export Entries page.
                   *
                   * @since 2.4.7
                   *
                   */
                  $forms = apply_filters('gform_export_entries_forms', $forms);

                  foreach ($forms as $form) {
                      ?>
                    <option value="<?php echo absint($form->id) ?>"><?php echo esc_html($form->title) ?></option>
                      <?php
                  }
                  ?>
              </select>

            </td>
          </tr>
          <tr id="export_field_container" valign="top" style="display: none;">
            <th scope="row">
              <label for="export_fields"><?php esc_html_e('Select Fields', 'gravityforms'); ?></label> <?php gform_tooltip('export_select_fields') ?>
            </th>
            <td>
              <ul id="export_field_list">
              </ul>
            </td>
          </tr>
          <tr id="export_filter_container" valign="top" style="display: none;">
            <th scope="row">
              <label><?php esc_html_e('Conditional Logic', 'gravityforms'); ?></label> <?php gform_tooltip('export_conditional_logic') ?>
            </th>
            <td>
              <div id="export_filters">
                <!--placeholder-->
              </div>

            </td>
          </tr>
          <tr id="export_date_container" valign="top" style="display: none;">
            <th scope="row">
              <label for="export_date"><?php esc_html_e('Select Date Range', 'gravityforms'); ?></label> <?php gform_tooltip('export_date_range') ?>
            </th>
            <td>
              <div>
                            <span style="width:150px; float:left; ">
                                <input type="text" id="export_date_start" name="export_date_start" style="width:90%" />
                                <strong><label for="export_date_start" style="display:block;"><?php esc_html_e('Start', 'gravityforms'); ?></label></strong>
                            </span>

                <span style="width:150px; float:left;">
                                <input type="text" id="export_date_end" name="export_date_end" style="width:90%" />
                                <strong><label for="export_date_end" style="display:block;"><?php esc_html_e('End', 'gravityforms'); ?></label></strong>
                            </span>

                <div style="clear: both;"></div>
                  <?php esc_html_e('Date Range is optional, if no date range is selected all entries will be exported.', 'gravityforms'); ?>
              </div>
            </td>
          </tr>
        </table>
        <ul>
          <li id="export_submit_container" style="display:none; clear:both;">
            <br /><br />
            <button id="submit_button" class="button button-large button-primary"><?php esc_attr_e('Download Export File', 'gravityforms'); ?></button>
            <span id="please_wait_container" style="display:none; margin-left:15px;">
                        <i class='gficon-gravityforms-spinner-icon gficon-spin'></i> <?php esc_html_e('Exporting entries. Progress:', 'gravityforms'); ?>
	                    <span id="progress_container">0%</span>
                    </span>
          </li>
        </ul>
      </form>

        <?php
        self::page_footer();
    }

    // modified to get all data from form and return as a string.
    public static function start_export($form, $offset = 0, $export_id = '') {

        $time_start = microtime(true);

        /***
         * Allows the export max execution time to be changed.
         *
         * When the max execution time is reached, the export routine stop briefly and submit another AJAX request to continue exporting entries from the point it stopped.
         *
         * @param int   20    The amount of time, in seconds, that each request should run for.  Defaults to 20 seconds.
         * @param array $form The Form Object
         *
         * @since 2.0.3.10
         *
         */
        $max_execution_time = apply_filters('gform_export_max_execution_time', 20, $form); // seconds

        $form_id = $form['id'];
        $fields  = $_POST['export_field'];

        $start_date = rgpost('export_date_start');
        $end_date   = rgpost('export_date_end');

        $search_criteria['status']        = 'active';
        $search_criteria['field_filters'] = GFCommon::get_field_filters_from_post($form);
        if (!empty($start_date)) {
            $search_criteria['start_date'] = $start_date;
        }

        if (!empty($end_date)) {
            $search_criteria['end_date'] = $end_date;
        }

        //$sorting = array( 'key' => 'date_created', 'direction' => 'DESC', 'type' => 'info' );
        $sorting = ['key' => 'id', 'direction' => 'DESC', 'type' => 'info'];

        $form = self::add_default_export_fields($form);

        $total_entry_count     = GFAPI::count_entries($form_id, $search_criteria);
        $page_size             = $total_entry_count;
        $remaining_entry_count = $offset == 0 ? $total_entry_count : $total_entry_count - $offset;

        $write_file = '';
        $lines      = '';

        // Set the separator
        $separator = gf_apply_filters(['gform_export_separator', $form_id], ',', $form_id);

        $field_rows = self::get_field_row_count($form, $fields, $remaining_entry_count);

        if ($offset == 0) {
            GFCommon::log_debug(__METHOD__ . '(): Processing request for form #' . $form_id);

            /**
             * Allows the BOM character to be excluded from the beginning of entry export files.
             *
             * @param bool $include_bom Whether or not to include the BOM characters. Defaults to true.
             * @param array $form The Form Object.
             *
             * @since 2.1.1.21
             *
             */
            $include_bom = apply_filters('gform_include_bom_export_entries', true, $form);

            //Adding BOM marker for UTF-8
            $lines = $include_bom ? chr(239) . chr(187) . chr(191) : '';

            //writing header
            $headers = [];
            foreach ($fields as $field_id) {
                $field = RGFormsModel::get_field($form, $field_id);
                $label = gf_apply_filters([
                    'gform_entries_field_header_pre_export',
                    $form_id,
                    $field_id
                ], GFCommon::get_label($field, $field_id), $form, $field);
                $value = str_replace('"', '""', $label);

                GFCommon::log_debug("GFExport::start_export(): Header for field ID {$field_id}: {$value}");

                if (strpos($value, '=') === 0) {
                    // Prevent Excel formulas
                    $value = "'" . $value;
                }

                $headers[$field_id] = $value;

                $subrow_count = isset($field_rows[$field_id]) ? intval($field_rows[$field_id]) : 0;
                if ($subrow_count == 0) {
                    $lines .= '"' . $value . '"' . $separator;
                } else {
                    for ($i = 1; $i <= $subrow_count; $i++) {
                        $lines .= '"' . $value . ' ' . $i . '"' . $separator;
                    }
                }

                //GFCommon::log_debug( "GFExport::start_export(): Lines: {$lines}" );
            }
            $lines = substr($lines, 0, strlen($lines) - 1) . "\n";

            $write_file = $lines;

            GFCommon::log_debug(__METHOD__ . '(): search criteria: ' . print_r($search_criteria, true));
            GFCommon::log_debug(__METHOD__ . '(): sorting: ' . print_r($sorting, true));
        }

        $lines = '';
        // Paging through results for memory issues
        while ($remaining_entry_count > 0) {

            $paging = [
                'offset'    => $offset,
                'page_size' => $page_size
            ];

            GFCommon::log_debug(__METHOD__ . '(): paging: ' . print_r($paging, true));

            $leads = GFAPI::get_entries($form_id, $search_criteria, $sorting, $paging);

            $leads = gf_apply_filters(['gform_leads_before_export', $form_id], $leads, $form, $paging);

            foreach ($leads as $lead) {
                $lines .= self::get_entry_export_line($lead, $form, $fields, $field_rows, $separator);
                $lines .= "\n";
            }

            $offset                += $page_size;
            $remaining_entry_count -= $page_size;

            if (!seems_utf8($lines)) {
                $lines = utf8_encode($lines);
            }

            $lines = apply_filters('gform_export_lines', $lines);

            $write_file .= $lines;

            $time_end       = microtime(true);
            $execution_time = ($time_end - $time_start);

            $lines = '';
        }

        $complete = $remaining_entry_count <= 0;

        /**
         * Fires after exporting all the entries in form
         *
         * @param array $form The Form object to get the entries from
         * @param string $start_date The start date for when the export of entries should take place
         * @param string $end_date The end date for when the export of entries should stop
         * @param array $fields The specified fields where the entries should be exported from
         * @param string $export_id A unique ID for the export.
         *
         * @since 1.9.3
         *
         * @since 2.4.5.11 Added the $export_id param.
         */
        do_action('gform_post_export_entries', $form, $start_date, $end_date, $fields, $export_id);

        $offset = $complete ? 0 : $offset;

        $status = [
            'status'   => $complete ? 'complete' : 'in_progress',
            'offset'   => $offset,
            'exportId' => $export_id,
            'progress' => $remaining_entry_count > 0 ? intval(100 - ($remaining_entry_count / $total_entry_count) * 100) . '%' : ''
        ];

        GFCommon::log_debug(__METHOD__ . '(): Status: ' . print_r($status, 1));

        $status['content'] = $write_file;

        return $status;
    }

    /**
     * Handles the export request from the export entries page.
     *
     * @since 2.0.0
     */
    // modified to return export contents and headers
    public static function ajax_process_export() {
        check_admin_referer('rg_start_export', 'rg_start_export_nonce');

        if (!GFCommon::current_user_can_any('gravityforms_export_entries')) {
            die();
        }

        $offset    = absint(rgpost('offset'));
        $export_id = sanitize_key((rgpost('exportId')));

        $form_id = $_POST['export_form'];
        $form    = RGFormsModel::get_form_meta($form_id);

        if (empty($export_id)) {
            $export_id = wp_hash(uniqid('export', true));
            $export_id = sanitize_key($export_id);
        }

        $status = self::start_export($form, $offset, $export_id);

        $filename = sanitize_title_with_dashes($form['title']) . '-' . gmdate('Y-m-d', GFCommon::get_local_timestamp(time())) . '.csv';
        $charset  = get_option('blog_charset');
        header('Content-Description: File Transfer');
        header("Content-Disposition: attachment; filename=$filename");
        header('Content-Type: text/csv; charset=' . $charset, true);
        echo $status['content'];

        die();
    }
}
