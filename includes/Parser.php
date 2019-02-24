<?php

/**
 * Class Parser
 * @package HTML_To_ACF_Fields\Parser
 */
class Parser {

    var $html = '';
    var $selector = 'ftd-field';
    var $sub_field_selector = 'ftd-sub';
    var $field_prefix = 'field_';
    var $fields = array();
    var $already_parsed = array();
    var $wordpress_fields = array(
        'ftd-title',
        'ftd-content',
        'ftd-excerpt'
    );

    public function __construct() {
        // Simple HTML DOM
        include_once(FTD_PATH. 'vendors/simple_html_dom.php');
    }

    /**
     * Get fields of html
     */
    public function get_fields($page) {

        // Get the HTML from the given page
        $this->html = file_get_html($page);

        // Crawl elements
        $this->crawl_elements($this->selector);

        // Convert wordpress fields
        $this->convert_wordpress_fields();
        
        // Once the crawl is done, save the html in a var and return the value
        $doc_html = $this->html->save();
        $this->html->clear();
        unset($this->html);

        dump($doc_html);
        die();

        // Return fields
        return array(
            'html' => $doc_html,
            'fields' => $this->fields
        );
    }

    /**
     * Crawl elements
     */
    public function crawl_elements($selector, $parent_field = false) {

        // Start the crawl to get fields
        foreach($this->html->find('['.$selector.']') as &$element) {

            // Get $field settings
            $field = $this->get_field_final_settings($element);

            // Set parent field
            if($parent_field) {
                $field['parent'] = $parent_field['key'];
            }

            // Remove the selector attribute
            $element->removeAttribute($selector);

            // If element has al
            if(in_array($element->tag_start, $this->already_parsed)) {
                continue;
            }
            $this->already_parsed[] = $element->tag_start;

            $this->fields[] = $field;

            // Condition that handle all fields type
            if($field['type'] === 'repeater') {

                // Recursivly get sub fields
                $this->crawl_elements($this->sub_field_selector, $field);
                
                // Get the outer tag of the repeater div to surround it with twig "for" loop
                $div_to_repeat = $element->outertext();

                // Set the item name based on the parent name and trim "s" so the item is singular
                $item = rtrim($field['name'], "s");

                if($parent_field){ 
                    $parent_item = rtrim($parent_field['name'], "s");
                    $element->outertext = "{% for ".$item." in ".$parent_item.".".$field['name']." %}
                    ".$div_to_repeat."
                {% endfor %}";
                }else {
                    $element->outertext = "{% for ".$item." in post.meta('".$field['name']."') %}
                        ".$div_to_repeat."
                    {% endfor %}";
                }
            }else {
                // If simple field
                if($parent_field){
                    $item = rtrim($parent_field['name'], "s");
                    $element->innertext = "{{ ".$item.".".$field['name']." }}";
                }else {
                    $element->innertext = "{{ post.meta('".$field['name']."') }}";
                }
            }
        }
    }

    /**
     * Get field name and type
     */
    public function get_field_settings($element, $sub_field = false) {

        if(isset($element->attr[$this->selector])) {
            $selector = $this->selector;
        }else if(isset($element->attr[$this->sub_field_selector])) {
            $selector = $this->sub_field_selector;
        }else {
            return false;
        }

        list($label, $type) = explode('|',$element->attr[$selector], 2);

        if(!$type) {
            return false;
        }

        // Set all the settings for the fields
        $label = trim($label);
        $type  = trim($type);
        $name  = str_replace('-', '_', sanitize_title($label));

        return array(
            'label' => $label,
            'type'  => $type,
            'name'  => $name,
        );
    }

    /**
     * Get final field settings :
     * Add uniq id key to the fields
     */
    public function get_field_final_settings($element, $sub_field = false) {

        $field_settings = $this->get_field_settings($element, $sub_field);
        $field_settings['key'] = uniqid($this->field_prefix);

        return $field_settings;
    }

}