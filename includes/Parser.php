<?php

/**
 * Class Parser
 * @package HTML_To_ACF_Fields\Parser
 */
class Parser {

    var $html = '';
    var $selector = 'ftb-field';
    var $sub_field_selector = 'ftb-sub';
    var $field_prefix = 'field_';
    var $fields = array();
    var $already_parsed = array();

    var $wordpress_fields = array(
        'ftb-title' => 'post.title',
        'ftb-content' => 'post.content',
        'ftb-excerpt' => 'post.excerpt'
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
        $this->parse_wordpress_fields();
        
        // Once the crawl is done, save the html in a var and return the value
        $doc_html = $this->html->save();
        $this->html->clear();
        unset($this->html);
        // Return fields
        return array(
            'html' => $doc_html,
            'fields' => $this->fields
        );
    }

    /**
     * Crawl elements
     */
    public function crawl_elements($selector, $parent_field = false, $element = false) {

        if($element) {
            $query = $element->find('['.$selector.']');
        }else {
            $query = $this->html->find('['.$selector.']');
        }

        if(empty($query)) {
            return false;
        }

        // Start the crawl to get fields
        foreach($this->html->find('['.$selector.']') as $element) {

            // Get $field settings
            $field = $this->get_field_final_settings($element);

            // Set parent field
            if($parent_field) {
                $field['parent'] = $parent_field['key'];
            }

            // If element has already been parsed, do not parse it again
            if(in_array($element->tag_start, $this->already_parsed)) {
                continue;
            }
            $this->already_parsed[] = $element->tag_start;

            // Add this fields to $field
            $this->fields[] = $field;

            // Recursivly get sub fields
            $this->crawl_elements($this->sub_field_selector, $field, $element);
            
            // Convert to twig
            $this->convert_to_timber_twig($field, $element, $parent_field, $selector);
        }
    }
    
    /**
     * Convert 
     */
    public function parse_wordpress_fields(){
        foreach($this->wordpress_fields as $selector => $twig_variable) {

            $wordpress_field = $this->html->find('['.$selector.']');

            if(!$wordpress_field) {
                return false;
            }

            // Get only first result if a mistake has been done
            $wordpress_field = reset($wordpress_field);

            // Convert to TWIG
            $wordpress_field->innertext = "{{ ".$twig_variable." }}";
            $wordpress_field->removeAttribute($selector);
        }
    }

    /**
     * Convert to twig
     */
    public function convert_to_timber_twig($field, $element, $parent_field, $selector) {
        
        // Remove the selector attribute
        $element->removeAttribute($selector);

        // Condition that handle all fields type
        if($field['type'] === 'repeater') {

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

        }else if($field['type'] === 'image') {
            if($parent_field){
                $item = rtrim($parent_field['name'], "s");
                $element->setAttribute('src', "{{ Image(".$item.".".$field['name'].").src }}");
            }else {
                $element->setAttribute('src', "{{ Image(post.meta('".$field['name']."')).src }}");
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