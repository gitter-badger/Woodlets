<?php

namespace Neochic\Woodlets;

class TemplateManager
{
    protected $twig;
    protected $wpWrapper;
    protected $fieldTypeManager;
    protected $templates;
    protected $twigHelper;
    protected $widgetManager;
    protected $postTypes;
    protected $defaultTemplates = array(
        "page" => "pages/default.twig",
        "post" => "posts/default.twig",
        "attachment" => "attachment.twig",
        "404" => "404.twig",
        "category" => "category.twig",
        "tag" => "tag.twig",
        "archive" => "archive.twig",
        "search" => "search.twig",
        "list" => "list.twig"
    );

    public function __construct($twig, $wpWrapper, $widgetManager, $fieldTypeManager, $twigHelper) {
        $this->twig = $twig;
        $this->wpWrapper = $wpWrapper;
        $this->widgetManager = $widgetManager;
        $this->fieldTypeManager = $fieldTypeManager;
        $this->twigHelper = $twigHelper;
        $this->postTypes = $this->wpWrapper->applyFilters('post_types', array("page" => "pages", "post" => "posts"));
        $this->templates = array();

        foreach($this->postTypes as $postType => $directory) {
            $this->templates[$postType] = $this->wpWrapper->applyFilters($postType . '_templates', $this->twig->getLoader()->searchTemplates($directory . '/*.twig'));
        }
    }

    public function display($withoutParent = false) {
        $templateName = $this->getTemplateName($this->wpWrapper->getTemplateType());
        $template = $this->twig->loadTemplate($templateName);

        /*
         * If template is extending or no view/form block combination is used
         * the template should be rendered directly, else just render the view block.
         */
        if(($template->getParent(array()) && !$withoutParent) || !$template->hasBlock('view')) {
            return $template->render(array('woodlets' => $this->twigHelper));
        }

        return $template->renderBlock('view', array('woodlets' => $this->twigHelper));
    }

    public function getTemplateName($type = "page")
    {
        $template = $this->wpWrapper->applyFilters('default_template_' . $type, $this->_getDefaultTemplate($type));
        $data = $this->wpWrapper->getPostMeta();
        $postType = $this->wpWrapper->getPostType();

        if (isset($data['template']) && $postType && isset($this->templates[$postType][$data['template']])) {
            $template = $data['template'];
        }

        //add main namespace to template to normalize the name
        if (strrpos($template, '@') !== 0) {
            $template = '@__main__/' . $template;
        }

        return $this->wpWrapper->applyFilters('template', $template);
    }

    public function getPostTypes() {
        return $this->postTypes;
    }

    public function getTemplateList($type = "page") {
        return $this->templates[$type];
    }

    public function getConfiguration() {
        $templateName = $this->getTemplateName($this->wpWrapper->getTemplateType());
        $template = new Template($templateName, $this->twig, $this->widgetManager, $this->fieldTypeManager);
        return $template->getConfig();
    }

    protected function _getDefaultTemplate($type) {
        if (isset($this->defaultTemplates[$type])) {
            return $this->defaultTemplates[$type];
        }

        /*
         * it may be a custom post type
         */
        if(isset($this->postTypes[$type])) {
            $default = $this->twig->getLoader()->searchTemplates($this->postTypes[$type] . '/default.twig');
            if (count($default)) {
                return $this->postTypes[$type] . '/default.twig';
            }

            /*
             * if the custom template doesn't have a default template fall back to page template
             */
            return $this->defaultTemplates['page'];
        }

        /*
         * invalid type => 404
         */
        return $this->defaultTemplates['404'];
    }
}
