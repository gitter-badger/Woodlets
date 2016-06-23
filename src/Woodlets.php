<?php
namespace Neochic\Woodlets;

class Woodlets
{
    protected $wpWrapper;
    protected $container;

    public function __construct($container, $wpWrapper)
    {
        $this->wpWrapper = $wpWrapper;
        $this->container = $container;
    }

    public function init()
    {
        $this->wpWrapper->addAction('neochic_woodlets_render_template', function () {
            echo $this->container['templateManager']->display();
        });

        $this->wpWrapper->addAction('plugins_loaded', function () {
            $this->wpWrapper->loadPluginTextdomain('woodlets', false, basename($this->container["basedir"]) . "/languages");
        });

        $this->wpWrapper->addAction('widgets_init', function () {
            $this->container['widgetManager']->addWidgets();
        });

        $this->wpWrapper->addAction('customize_register', function ($wp_customize) {
            $themeCustomizer = new ThemeCustomizer($wp_customize, $this->container);
            $this->wpWrapper->doAction('theme', $themeCustomizer);
            $themeCustomizer->addControls();
        });

        $this->wpWrapper->addFilter('the_editor', function ($editor) {
            //we only do want to change main editor and keep reply editor intact
            if (strpos($editor, 'id="content"') === false) {
                return $editor;
            }

            //don't replace editor if we're not on page editing page
            if (!in_array($this->wpWrapper->pageNow() ,array("post.php", "post-new.php"))) {
                return $editor;
            }

            $editorManager = $this->container['editorManager'];
            $woodletsEditor = $editorManager->getEditor();

            if(!$woodletsEditor) {
                return $editor;
            }

            //note: escape % because wp is throwing it through printf
            return str_replace("%", "%%", $woodletsEditor);
        });

        $this->wpWrapper->addFilter('content_save_pre', function($content) {
            if (!in_array($this->wpWrapper->pageNow() ,array("post.php", "post-new.php", "revision.php"))) {
                return $content;
            }

            $data = null;

            if ($this->wpWrapper->pageNow() === 'revision.php' && $_GET['action'] === 'restore') {
                $data = $this->wpWrapper->getPostMeta(null, $_GET['revision'], true);
            } else {
                $data = $this->container['editorManager']->preparePostData();
            }


            $templateManager = $this->container['templateManager'];
            $data = $this->wpWrapper->unslash($data);
            $this->container['twigHelper']->setPostMeta($data);

            $config = $templateManager->getConfiguration();
            if (!is_array($config["columns"]) || count($config["columns"]) < 1) {
                return $content;
            }

            return $templateManager->display(true);
        });

        $this->wpWrapper->addAction('save_post', function ($postId) {
            $post = $this->wpWrapper->getPost();

            //check nonce to prevent XSS
            if (!isset($_POST['_wpnonce']) || !$this->wpWrapper->verifyNonce($_POST['_wpnonce'], 'update-post_' . $postId)) {
                return;
            }

            //check user permission
            if (!$this->wpWrapper->isAllowed('edit_page', $post->ID)) {
                return;
            }

            $this->container['editorManager']->save($postId);
            $this->container['pageConfigurationManager']->save();
        });

        $this->wpWrapper->addAction('wp_restore_post_revision', function($postId, $revisionId) {
            $this->container['editorManager']->revert($revisionId);
        }, 90, 2);
        
        $this->wpWrapper->addAction('add_meta_boxes', function () {
            $this->container['pageConfigurationManager']->addMetaBoxes();
            $this->container['editorManager']->addMetaBox();
        });

        $this->wpWrapper->addFilter('the_content', function ($content) {
            if ($this->wpWrapper->isPage()) {
                $this->container['twigHelper']->reloadPostMeta();
                $templateConfig = $this->container['templateManager']->getConfiguration();

                //if there is no column just display the whole template
                if (count($templateConfig["columns"]) < 1) {
                    return $this->container['templateManager']->display(true);
                }

                //else display the main column
                ob_start();
                $this->container['twigHelper']->getCol($templateConfig['settings']['mainCol']);
                return ob_get_clean();
            }

            return $content;
        }, -99999999999999999);

        $this->wpWrapper->addAction( 'show_user_profile', function($user) {
            $this->container['profileManager']->form($user);
        });
        $this->wpWrapper->addAction( 'edit_user_profile', function($user) {
            $this->container['profileManager']->form($user);
        });
        $this->wpWrapper->addAction( 'personal_options_update', function($userId) {
            $this->container['profileManager']->save($userId);
        });
        $this->wpWrapper->addAction( 'edit_user_profile_update', function($userId) {
            $this->container['profileManager']->save($userId);
        });

        $this->wpWrapper->addAction('admin_enqueue_scripts', function ($hook) {
            $isCustomize = ($hook === 'widgets.php' && $this->wpWrapper->pageNow() === 'customize.php');
            $isWidgets = ($hook === 'widgets.php' && $this->wpWrapper->pageNow() === 'widgets.php');

            if (in_array($hook, array('post-new.php', 'post.php', 'profile.php')) || $isCustomize || $isWidgets) {
                $this->container['scriptsManager']->addScripts();
            }
        });


        $this->wpWrapper->addAction('wp_ajax_neochic_woodlets_get_widget_list', function () {
            /**
             * @var \Neochic\Woodlets\WidgetManager $widgetManager;
             */
            $widgetManager = $this->container['widgetManager'];

            echo $widgetManager->getWidgetList(isset($_REQUEST['allowed']) ? $_REQUEST['allowed'] : array());
            $this->wpWrapper->wpDie();
        });

        $this->wpWrapper->addAction('wp_ajax_neochic_woodlets_get_widget_preview', function () {
            $instance = json_decode($this->wpWrapper->unslash($_REQUEST['instance']), true);
            $widget = $this->wpWrapper->unslash($_REQUEST['widget']);
            echo $this->container['editorManager']->getWidgetPreview($widget, $instance);
            wp_die();
        });

        $this->wpWrapper->addAction('wp_ajax_neochic_woodlets_get_widget_form', function () {
            $instance = isset($_REQUEST['instance']) ? $_REQUEST['instance'] : array();
            $instance = json_decode($this->wpWrapper->unslash($instance), true);
            $widgetManager = $this->container['widgetManager'];
            $widget = $widgetManager->getWidget($_REQUEST['widget']);
            if($widget) {
                $widget->form($instance);
            }
            wp_die();
        });

        $this->wpWrapper->addAction('wp_ajax_neochic_woodlets_get_widget_update', function () {
            $widgetManager = $this->container['widgetManager'];
            $widgetName = $this->wpWrapper->unslash($_REQUEST['widget']);
            $widget = $widgetManager->getWidget($widgetName);

            $widgetData = $this->wpWrapper->unslash($_REQUEST['widget-' . $widget->id_base]);

            echo json_encode($widget->update(current($widgetData), array()));
            wp_die();
        });
    }
}
