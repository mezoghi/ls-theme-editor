<?php
/**
 * Plugin Name:       LS - Theme Editor
 * Plugin URI:        https://#
 * Description:       Enhances the developer experience for YOOtheme child themes by allowing direct edits from the WordPress admin.
 * Version:           1.3.0
 * Author:            Lazar Spasov
 * Author URI:        https://#
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       ls-theme-editor
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

define( 'LS_THEME_EDITOR_VERSION', '1.3.0' );
define( 'LS_THEME_EDITOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'LS_THEME_EDITOR_URL', plugin_dir_url( __FILE__ ) );

/**
 * The core plugin class.
 */
final class LS_Theme_Editor {

    protected static $_instance = null;

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_ajax_ls_save_less_file', array( $this, 'save_less_file' ) );
        add_action( 'wp_ajax_ls_restore_less_backup', array( $this, 'restore_less_backup' ) );
        
        // This filter is kept as a good practice, but the core logic is now more direct.
        add_filter( 'upload_mimes', array( $this, 'add_custom_mime_types' ) );
    }
    
    public function add_custom_mime_types( $mimes ) {
        $mimes['less'] = 'text/plain'; // Using text/plain is more compatible
        return $mimes;
    }

    public function admin_menu() {
        add_menu_page(
            __( 'LS Theme Editor', 'ls-theme-editor' ),
            'LS Theme Editor',
            'manage_options',
            'ls-theme-editor',
            array( $this, 'admin_page_content' ),
            'dashicons-edit-page',
            80
        );
    }

    public function enqueue_scripts( $hook ) {
        if ( 'toplevel_page_ls-theme-editor' !== $hook ) {
            return;
        }
        wp_enqueue_style( 'ls-theme-editor-style', LS_THEME_EDITOR_URL . 'assets/css/admin.css', array(), LS_THEME_EDITOR_VERSION );
        wp_enqueue_script( 'ls-theme-editor-script', LS_THEME_EDITOR_URL . 'assets/js/admin.js', array( 'jquery' ), LS_THEME_EDITOR_VERSION, true );
        wp_localize_script( 'ls-theme-editor-script', 'lsThemeEditor', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'ls-theme-editor-nonce' ),
        ) );
    }

    public function admin_page_content() {
        ?>
        <div class="wrap ls-theme-editor-wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'LS - Theme Editor for YOOtheme', 'ls-theme-editor' ); ?></h1>
            <hr class="wp-header-end">
            
            <?php $this->handle_form_actions(); ?>
            
            <?php if ( ! $this->is_yootheme_child_active() ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e( 'A YOOtheme child theme is not currently active. This plugin requires a YOOtheme child theme to function.', 'ls-theme-editor' ); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>

            <div class="nav-tab-wrapper">
                <a href="?page=ls-theme-editor&tab=general" class="nav-tab <?php echo $this->get_active_tab( 'general' ); ?>"><?php esc_html_e( 'General', 'ls-theme-editor' ); ?></a>
                <a href="?page=ls-theme-editor&tab=screenshots" class="nav-tab <?php echo $this->get_active_tab( 'screenshots' ); ?>"><?php esc_html_e( 'Screenshots', 'ls-theme-editor' ); ?></a>
                <a href="?page=ls-theme-editor&tab=edit_less" class="nav-tab <?php echo $this->get_active_tab( 'edit_less' ); ?>"><?php esc_html_e( 'Edit LESS', 'ls-theme-editor' ); ?></a>
                <a href="?page=ls-theme-editor&tab=append_less" class="nav-tab <?php echo $this->get_active_tab( 'append_less' ); ?>"><?php esc_html_e( 'Append LESS', 'ls-theme-editor' ); ?></a>
            </div>

            <div class="ls-theme-editor-content">
                <?php
                $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
                settings_errors( 'ls-theme-editor-notices' );
                
                echo '<div class="ls-card">';
                switch ( $tab ) {
                    case 'screenshots':
                        $this->render_screenshots_tab();
                        break;
                    case 'edit_less':
                        $this->render_edit_less_tab();
                        break;
                    case 'append_less':
                        $this->render_append_less_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                echo '</div>';
                ?>
            </div>
        </div>
        <?php
        $this->render_confirmation_modal();
    }
    
    private function render_confirmation_modal() {
        ?>
        <div id="ls-confirm-modal" class="ls-modal-overlay" style="display:none;">
            <div class="ls-modal-content">
                <div class="ls-modal-header">
                    <h3><?php esc_html_e('Are you sure?', 'ls-theme-editor'); ?></h3>
                    <button type="button" class="ls-modal-close">&times;</button>
                </div>
                <div class="ls-modal-body">
                    <p id="ls-modal-text"></p>
                </div>
                <div class="ls-modal-footer">
                    <button type="button" class="button button-secondary" id="ls-modal-cancel"><?php esc_html_e('Cancel', 'ls-theme-editor'); ?></button>
                    <button type="button" class="button button-primary" id="ls-modal-confirm"><?php esc_html_e('Confirm', 'ls-theme-editor'); ?></button>
                </div>
            </div>
        </div>
        <?php
    }

    private function handle_form_actions() {
        if ( isset( $_POST['ls_update_theme_name_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ls_update_theme_name_nonce'] ), 'ls_update_theme_name' ) ) {
            $this->update_theme_name();
        }
        if ( isset( $_POST['ls_update_screenshots_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ls_update_screenshots_nonce'] ), 'ls_update_screenshots' ) ) {
            $this->update_screenshots();
        }
        if ( isset( $_POST['ls_append_less_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['ls_append_less_nonce'] ), 'ls_append_less' ) ) {
            $this->append_less_content();
        }
    }

    private function render_general_tab() {
        $child_theme = wp_get_theme();
        $theme_name = $child_theme->get( 'Name' );
        ?>
        <h2><?php esc_html_e( 'Child Theme Name', 'ls-theme-editor' ); ?></h2>
        <p><?php esc_html_e( 'This will update the "Theme Name" in your child theme\'s style.css file.', 'ls-theme-editor' ); ?></p>
        <form method="post" action="">
            <?php wp_nonce_field( 'ls_update_theme_name', 'ls_update_theme_name_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><label for="theme_name"><?php esc_html_e( 'Theme Name', 'ls-theme-editor' ); ?></label></th>
                    <td>
                        <input type="text" id="theme_name" name="theme_name" value="<?php echo esc_attr( $theme_name ); ?>" class="regular-text" />
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Update Theme Name', 'ls-theme-editor' ) ); ?>
        </form>
        <?php
    }

    private function render_screenshots_tab() {
        $child_theme_dir = get_stylesheet_directory();
        $screenshot_url = get_stylesheet_directory_uri() . '/screenshot.png';
        $less_screenshot_url = get_stylesheet_directory_uri() . '/less/screenshot.png';
        ?>
        <h2><?php esc_html_e( 'Update Screenshots', 'ls-theme-editor' ); ?></h2>
        <p><?php esc_html_e( 'Upload new PNG images to replace your theme screenshots.', 'ls-theme-editor' ); ?></p>
        <form method="post" action="" enctype="multipart/form-data">
            <?php wp_nonce_field( 'ls_update_screenshots', 'ls_update_screenshots_nonce' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'Main Theme Screenshot', 'ls-theme-editor' ); ?></th>
                    <td>
                        <?php if ( file_exists( $child_theme_dir . '/screenshot.png' ) ) : ?>
                            <img class="ls-screenshot-preview" src="<?php echo esc_url($screenshot_url) . '?v=' . time(); ?>" alt="Main Screenshot" />
                        <?php endif; ?>
                        <input type="file" name="main_screenshot" accept="image/png" /><br>
                        <p class="description"><?php esc_html_e( 'Replaces screenshot.png in the theme root.', 'ls-theme-editor' ); ?></p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row"><?php esc_html_e( 'LESS Preview Screenshot', 'ls-theme-editor' ); ?></th>
                    <td>
                        <?php if ( file_exists( $child_theme_dir . '/less/screenshot.png' ) ) : ?>
                             <img class="ls-screenshot-preview" src="<?php echo esc_url($less_screenshot_url) . '?v=' . time(); ?>" alt="LESS Screenshot" />
                        <?php endif; ?>
                        <input type="file" name="less_screenshot" accept="image/png" /><br>
                        <p class="description"><?php esc_html_e( 'Replaces screenshot.png in the /less/ folder.', 'ls-theme-editor' ); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Update Screenshots', 'ls-theme-editor' ) ); ?>
        </form>
        <?php
    }

    private function render_edit_less_tab() {
        $less_dir = get_stylesheet_directory() . '/less/';
        if ( ! is_dir( $less_dir ) ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'The /less directory does not exist in your child theme.', 'ls-theme-editor' ) . '</p></div>';
            return;
        }
        $less_files = glob( $less_dir . '*.less' );
        if ( empty( $less_files ) ) {
            echo '<h2>' . esc_html__( 'Edit LESS Files', 'ls-theme-editor' ) . '</h2>';
            echo '<div class="notice notice-info"><p>' . esc_html__( 'No .less files found in the /less directory.', 'ls-theme-editor' ) . '</p></div>';
            return;
        }
        echo '<h2>' . esc_html__( 'Edit LESS Files', 'ls-theme-editor' ) . '</h2>';
        echo '<p>' . esc_html__( 'Changes are saved automatically. A backup (.bak) is created before each save.', 'ls-theme-editor' ) . '</p>';
        foreach ( $less_files as $file_path ) {
            $file_name = basename( $file_path );
            $file_content = file_get_contents( $file_path );
            $backup_exists = file_exists( $file_path . '.bak' );
            ?>
            <div class="less-editor-container" id="less-container-<?php echo esc_attr( sanitize_key( $file_name ) ); ?>">
                <h4><span class="dashicons dashicons-media-text"></span> <?php echo esc_html( $file_name ); ?></h4>
                <textarea class="large-text code less-editor" rows="15" data-filename="<?php echo esc_attr( $file_name ); ?>"><?php echo esc_textarea( $file_content ); ?></textarea>
                <div class="less-editor-actions">
                    <button class="button button-primary save-less-btn" data-filename="<?php echo esc_attr( $file_name ); ?>"><?php esc_html_e( 'Save Changes', 'ls-theme-editor' ); ?></button>
                    <button class="button restore-less-btn" data-filename="<?php echo esc_attr( $file_name ); ?>" <?php disabled( ! $backup_exists ); ?>><?php esc_html_e( 'Restore Backup', 'ls-theme-editor' ); ?></button>
                    <span class="spinner"></span>
                    <div class="save-status"></div>
                </div>
            </div>
            <?php
        }
    }

    private function render_append_less_tab() {
        $target_file = get_stylesheet_directory() . '/less/theme.mysite.less';
        ?>
        <h2><?php esc_html_e( 'Append to theme.mysite.less', 'ls-theme-editor' ); ?></h2>
        <?php if (!file_exists($target_file)): ?>
             <div class="notice notice-warning"><p><?php printf(esc_html__( 'The target file %s does not exist. Please create it first in your child theme\'s /less/ directory.', 'ls-theme-editor' ), '<strong>theme.mysite.less</strong>'); ?></p></div>
        <?php else: ?>
            <p><?php esc_html_e( 'Upload a .less file. Its content will be validated and appended to theme.mysite.less.', 'ls-theme-editor' ); ?></p>
            <form method="post" action="" enctype="multipart/form-data">
                <?php wp_nonce_field( 'ls_append_less', 'ls_append_less_nonce' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><label for="append_less_file"><?php esc_html_e( 'LESS File to Append', 'ls-theme-editor' ); ?></label></th>
                        <td>
                            <input type="file" id="append_less_file" name="append_less_file" accept=".less" required />
                            <p class="description"><?php esc_html_e( 'The plugin validates for unbalanced curly brackets {} before appending.', 'ls-theme-editor' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Append Content', 'ls-theme-editor' ) ); ?>
            </form>
        <?php endif; ?>
        <?php
    }

    private function update_theme_name() {
        $new_name = sanitize_text_field( wp_unslash( $_POST['theme_name'] ) );
        if ( empty( $new_name ) ) {
            add_settings_error( 'ls-theme-editor-notices', 'empty_name', __( 'Theme name cannot be empty.', 'ls-theme-editor' ), 'error' );
            return;
        }
        $style_css_path = get_stylesheet_directory() . '/style.css';
        if ( ! is_writable( $style_css_path ) ) {
            add_settings_error( 'ls-theme-editor-notices', 'not_writable', __( 'style.css is not writable.', 'ls-theme-editor' ), 'error' );
            return;
        }
        copy( $style_css_path, $style_css_path . '.bak' );
        $css_content = file_get_contents( $style_css_path );
        $css_content = preg_replace( '/(Theme Name:)(.*)/', '$1 ' . $new_name, $css_content, 1 );
        if ( file_put_contents( $style_css_path, $css_content ) ) {
            add_settings_error( 'ls-theme-editor-notices', 'name_updated', __( 'Theme name updated successfully.', 'ls-theme-editor' ), 'updated' );
        } else {
            add_settings_error( 'ls-theme-editor-notices', 'update_failed', __( 'Failed to update theme name.', 'ls-theme-editor' ), 'error' );
        }
    }

    private function update_screenshots() {
        $child_theme_dir = get_stylesheet_directory();
        $files_to_process = array(
            'main_screenshot' => 'png',
            'less_screenshot' => 'png'
        );
        $updated = false;

        foreach ($files_to_process as $file_key => $expected_extension) {
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES[$file_key];
                
                // FINAL FIX: Direct extension check
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($file_extension !== $expected_extension) {
                    add_settings_error('ls-theme-editor-notices', 'invalid_type_png', __('Invalid file type. Please upload a .png file.', 'ls-theme-editor'), 'error');
                    continue;
                }

                $destination_path = ($file_key === 'main_screenshot')
                    ? $child_theme_dir . '/screenshot.png'
                    : $child_theme_dir . '/less/screenshot.png';

                $target_dir = dirname($destination_path);
                if (!is_dir($target_dir)) { wp_mkdir_p($target_dir); }

                if ( ! is_writable( $target_dir ) ) {
                    add_settings_error('ls-theme-editor-notices', 'dir_not_writable', sprintf(__('Directory %s is not writable.', 'ls-theme-editor'), $target_dir), 'error');
                    continue;
                }

                if ( move_uploaded_file( $file['tmp_name'], $destination_path ) ) {
                    $updated = true;
                } else {
                    add_settings_error('ls-theme-editor-notices', 'upload_failed', sprintf(__('Failed to upload %s.', 'ls-theme-editor'), $file['name']), 'error');
                }
            }
        }
        if ($updated) {
            add_settings_error('ls-theme-editor-notices', 'screenshots_updated', __('Screenshots updated successfully.', 'ls-theme-editor'), 'updated');
        }
    }

    private function append_less_content() {
        if ( ! isset( $_FILES['append_less_file'] ) || $_FILES['append_less_file']['error'] !== UPLOAD_ERR_OK ) {
            add_settings_error( 'ls-theme-editor-notices', 'upload_error', __( 'Error uploading file.', 'ls-theme-editor' ), 'error' );
            return;
        }
        $file = $_FILES['append_less_file'];
        
        // FINAL FIX: Direct extension check instead of relying on WordPress mime type validation
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'less') {
            add_settings_error('ls-theme-editor-notices', 'invalid_type_less', __('Invalid file type. Please upload a .less file.', 'ls-theme-editor'), 'error');
            return;
        }

        $content = file_get_contents( $file['tmp_name'] );
        if ( substr_count( $content, '{' ) !== substr_count( $content, '}' ) ) {
            add_settings_error( 'ls-theme-editor-notices', 'unbalanced_brackets', __( 'Validation failed: The uploaded file has unbalanced curly brackets {}.', 'ls-theme-editor' ), 'error' );
            return;
        }

        $target_file = get_stylesheet_directory() . '/less/theme.mysite.less';
        if ( ! file_exists($target_file) || ! is_writable( $target_file ) ) {
            add_settings_error( 'ls-theme-editor-notices', 'target_not_writable', __( 'Target file theme.mysite.less is not found or not writable.', 'ls-theme-editor' ), 'error' );
            return;
        }

        copy( $target_file, $target_file . '.bak' );
        if ( file_put_contents( $target_file, "\n\n// Appended on " . date('Y-m-d H:i:s') . "\n" . $content, FILE_APPEND ) ) {
            add_settings_error( 'ls-theme-editor-notices', 'append_success', __( 'Content appended successfully.', 'ls-theme-editor' ), 'updated' );
        } else {
            add_settings_error( 'ls-theme-editor-notices', 'append_failed', __( 'Failed to append content.', 'ls-theme-editor' ), 'error' );
        }
    }
    
    public function save_less_file() {
        check_ajax_referer( 'ls-theme-editor-nonce', 'nonce' );
        $filename = sanitize_file_name( $_POST['filename'] );
        $content = wp_unslash( $_POST['content'] );
        $less_file_path = get_stylesheet_directory() . '/less/' . $filename;
        if ( ! file_exists( $less_file_path ) || ! is_writable( dirname( $less_file_path ) ) ) {
            wp_send_json_error( array( 'message' => __( 'File not found or directory not writable.', 'ls-theme-editor' ) ) );
        }
        copy( $less_file_path, $less_file_path . '.bak' );
        if ( file_put_contents( $less_file_path, $content ) !== false ) {
            wp_send_json_success( array( 'message' => __( 'File saved successfully!', 'ls-theme-editor' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error saving file.', 'ls-theme-editor' ) ) );
        }
    }

    public function restore_less_backup() {
        check_ajax_referer( 'ls-theme-editor-nonce', 'nonce' );
        $filename = sanitize_file_name( $_POST['filename'] );
        $less_file_path = get_stylesheet_directory() . '/less/' . $filename;
        $backup_path = $less_file_path . '.bak';
        if ( ! file_exists( $backup_path ) ) {
            wp_send_json_error( array( 'message' => __( 'Backup file not found.', 'ls-theme-editor' ) ) );
        }
        if ( copy( $backup_path, $less_file_path ) ) {
            $new_content = file_get_contents( $less_file_path );
            wp_send_json_success( array( 
                'message' => __( 'Backup restored successfully!', 'ls-theme-editor' ),
                'content' => $new_content
            ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Error restoring backup.', 'ls-theme-editor' ) ) );
        }
    }

    private function is_yootheme_child_active() {
        $theme = wp_get_theme();
        return $theme->get( 'Template' ) === 'yootheme';
    }

    private function get_active_tab( $tab_name ) {
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        return $current_tab === $tab_name ? 'nav-tab-active' : '';
    }
}

function ls_theme_editor() {
    return LS_Theme_Editor::instance();
}
$GLOBALS['ls_theme_editor'] = ls_theme_editor();
?>
