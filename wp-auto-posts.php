<?php
/**
 * Plugin Name: WP Auto Posts (v0.5) — Scalable Scheduler
 * Description: Auto-post ribuan artikel dari CSV. Optimasi Action Scheduler + WP-Cron fallback, batch processing, log, dan kontrol penuh.
 * Version: 0.5
 * Author: ChatGPT
 */

if (!defined('ABSPATH')) exit;

class WP_Auto_Posts_V05 {
    private static $instance = null;
    public static function instance(){
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private $version = '0.5';
    private $upload_dir;
    private $default_interval = 5; // menit
    private $default_batch_size = 500;

    private function __construct(){
        $uploads = wp_upload_dir();
        $this->upload_dir = trailingslashit($uploads['basedir']) . 'wp-auto-posts';

        register_activation_hook(__FILE__, array($this,'activation'));
        register_deactivation_hook(__FILE__, array($this,'deactivation'));

        add_filter('cron_schedules', array($this,'add_minute_cron'));
        add_action('wpap_check_waiting_tasks', array($this,'check_and_schedule_waiting'));

        add_action('admin_menu', array($this,'admin_menu'));
        add_action('admin_enqueue_scripts', array($this,'admin_assets'));

        add_action('admin_post_wpap_upload_csv', array($this,'handle_csv_upload'));
        add_action('wp_ajax_wpap_requeue', array($this,'ajax_requeue'));
        add_action('wp_ajax_wpap_force_run_project', array($this,'ajax_force_run_project'));

        // task runner
        add_action('wpap_run_task', array($this,'run_task'));
        // batch processor
        add_action('wpap_process_csv_batch', array($this,'process_csv_batch'));
    }

    /* -------------------------------------------------------------
     *  AKTIVASI & DEAKTIVASI
     * ------------------------------------------------------------- */
    public function activation(){
        if (!file_exists($this->upload_dir)) wp_mkdir_p($this->upload_dir);
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $projects = $wpdb->prefix . 'wpap_projects';
        $tasks = $wpdb->prefix . 'wpap_tasks';
        $logs = $wpdb->prefix . 'wpap_logs';

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql = "CREATE TABLE $projects (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            csv_path varchar(255) NOT NULL,
            title_template text NULL,
            content_template longtext NULL,
            thumbnail_id bigint(20) unsigned NULL,
            category_id bigint(20) unsigned NULL,
            tags text NULL,
            interval_minutes int(11) NOT NULL DEFAULT {$this->default_interval},
            created_at datetime NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'waiting',
            PRIMARY KEY (id)
        ) $charset;";
        dbDelta($sql);

        $sql = "CREATE TABLE $tasks (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            project_id bigint(20) unsigned NOT NULL,
            row_index bigint(20) unsigned NOT NULL,
            data longtext NOT NULL,
            scheduled_at datetime NOT NULL,
            status varchar(50) NOT NULL DEFAULT 'waiting',
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";
        dbDelta($sql);

        $sql = "CREATE TABLE $logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            task_id bigint(20) unsigned NULL,
            project_id bigint(20) unsigned NULL,
            message text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id)
        ) $charset;";
        dbDelta($sql);

        // Cron checker tiap menit
        if (!wp_next_scheduled('wpap_check_waiting_tasks')){
            wp_schedule_event(time(), 'every_minute', 'wpap_check_waiting_tasks');
        }
    }

    public function deactivation(){
        wp_clear_scheduled_hook('wpap_check_waiting_tasks');
    }

    public function add_minute_cron($schedules){
        if (!isset($schedules['every_minute'])){
            $schedules['every_minute'] = array('interval' => 60, 'display' => __('Setiap Menit'));
        }
        return $schedules;
    }
    /* -------------------------------------------------------------
     *  BAGIAN 2 — HELPER SCHEDULING + ADMIN MENU & ASSETS
     * ------------------------------------------------------------- */
    /**
     * Hapus semua jadwal yang terikat ke task_id.
     * Menangani kedua bentuk args (assoc untuk AS, positional untuk WP-Cron)
     *
     * @param int $task_id
     */
    private function unschedule_task($task_id){
        $task_id = intval($task_id);
        $as_args = array('task_id' => $task_id);
        $wp_args = array($task_id);

        if (function_exists('as_unschedule_action')){
            // coba unschedule dengan group & tanpa group
            as_unschedule_action('wpap_run_task', $as_args, 'wp-auto-posts');
            as_unschedule_action('wpap_run_task', $as_args);
        }

        // Hapus WP-Cron events (positional)
        wp_clear_scheduled_hook('wpap_run_task', $wp_args);
        // Juga coba hapus dengan associative array bentuk lama (jika ada)
        wp_clear_scheduled_hook('wpap_run_task', $as_args);
    }

    /**
     * Ambil ID author default untuk posting yang dibuat oleh cron.
     * Prioritas:
     * 1) option 'wpap_default_author' (jika diset)
     * 2) user pertama dengan capability 'publish_posts'
     * 3) fallback ke 1
     */
    private function get_default_author(){
        $author = intval(get_option('wpap_default_author', 0));
        if ($author && get_userdata($author)) return $author;

        $users = get_users(array(
            'orderby' => 'ID',
            'order' => 'ASC',
            'number' => 1,
            'who' => '',
            'capability' => 'publish_posts'
        ));
        if (!empty($users) && isset($users[0]->ID)) return intval($users[0]->ID);

        return 1;
    }

    /* -----------------------
     * ADMIN: enqueue assets + localize
     * ---------------------- */
    public function admin_assets($hook){
        // hanya load untuk halaman plugin (cek key page slug wp-auto-posts / wpap)
        if (strpos($hook, 'wp-auto-posts') === false && strpos($hook, 'wpap') === false) return;

        wp_enqueue_media();
        wp_enqueue_script('wpap-admin', plugin_dir_url(__FILE__).'assets/wpap-admin.js', array('jquery'), $this->version, true);
        wp_enqueue_style('wpap-admin', plugin_dir_url(__FILE__).'assets/wpap-admin.css', array(), $this->version);

        // localize untuk AJAX nonce & admin-ajax url
        wp_localize_script('wpap-admin', 'WPAP_Admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_requeue' => wp_create_nonce('wpap_requeue'),
            'nonce_force_run' => wp_create_nonce('wpap_force_run'),
            'i18n_confirm_requeue' => __('Requeue semua failed tasks untuk project ini?', 'wp-auto-posts'),
            'i18n_confirm_force' => __('Force run project sekarang? (akan menjalankan task waiting segera)', 'wp-auto-posts')
        ));
    }

    /* -----------------------
     * ADMIN: menu items
     * ---------------------- */
    public function admin_menu(){
        // Halaman utama: daftar semua project
        add_menu_page(
            __('WP Auto Posts', 'wp-auto-posts'),
            'WP Auto Posts',
            'manage_options',
            'wp-auto-posts',
            array($this, 'page_projects_list'), // 🟢 ganti ke fungsi daftar project
            'dashicons-schedule',
            26
        );
    
        // Submenu: tambah project baru
        add_submenu_page(
            'wp-auto-posts',
            __('New Project', 'wp-auto-posts'),
            __('New Project', 'wp-auto-posts'),
            'manage_options',
            'wpap-new-project',
            array($this, 'page_new_project')
        );
    }
    
    /* -------------------------------------------------------------
     *  BAGIAN 3 — DASHBOARD ADMIN (PROJECTS & LOGS)
     * ------------------------------------------------------------- */

    /**
     * Halaman utama plugin — daftar proyek + log.
     */
    public function page_dashboard(){
        if (!current_user_can('manage_options')) wp_die(__('Kamu tidak punya izin.', 'wp-auto-posts'));
        global $wpdb;

        $projects_tbl = $wpdb->prefix.'wpap_projects';
        $logs_tbl = $wpdb->prefix.'wpap_logs';

        $projects = $wpdb->get_results("SELECT * FROM $projects_tbl ORDER BY id DESC LIMIT 100");
        $logs = $wpdb->get_results("SELECT * FROM $logs_tbl ORDER BY id DESC LIMIT 50");

        ?>
        <div class="wrap">
            <h1>📦 WP Auto Posts</h1>
            <p>Versi <?= esc_html($this->version); ?> — Jadwalkan ribuan artikel dari CSV secara otomatis.</p>

            <hr>

            <h2>🗂️ Daftar Proyek</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nama</th>
                        <th>Status</th>
                        <th>Interval</th>
                        <th>Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($projects): foreach ($projects as $p): ?>
                    <tr>
                        <td><?= intval($p->id); ?></td>
                        <td><?= esc_html($p->name); ?></td>
                        <td><?= esc_html($p->status); ?></td>
                        <td><?= intval($p->interval_minutes); ?> menit</td>
                        <td><?= esc_html($p->created_at); ?></td>
                        <td>
                            <a href="<?= admin_url('admin.php?page=wpap-new-project&project_id='.$p->id); ?>" class="button">Lihat</a>
                            <button class="button wpap-requeue" data-id="<?= intval($p->id); ?>"><?= __('Requeue Failed','wp-auto-posts'); ?></button>
                            <button class="button wpap-force-run" data-id="<?= intval($p->id); ?>"><?= __('Force Run','wp-auto-posts'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="6"><em>Belum ada proyek.</em></td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <hr>

            <h2>🧾 Log Terbaru</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Project</th>
                        <th>Task</th>
                        <th>Pesan</th>
                        <th>Waktu</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($logs): foreach ($logs as $l): ?>
                    <tr>
                        <td><?= intval($l->id); ?></td>
                        <td><?= intval($l->project_id); ?></td>
                        <td><?= intval($l->task_id); ?></td>
                        <td><?= esc_html($l->message); ?></td>
                        <td><?= esc_html($l->created_at); ?></td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5"><em>Tidak ada log.</em></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Halaman tambah proyek (upload CSV baru)
     */
    public function page_new_project(){
        if (!current_user_can('manage_options')) wp_die(__('Tidak punya izin.'));
        global $wpdb;

        $project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
        $project = null;
        if ($project_id){
            $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}wpap_projects WHERE id=%d", $project_id));
        }
        ?>
        <div class="wrap">
            <h1><?= $project ? '📁 Edit Project: '.esc_html($project->name) : '➕ Tambah Proyek Baru'; ?></h1>

            <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('wpap_upload_csv'); ?>
                <input type="hidden" name="action" value="wpap_upload_csv">

                <table class="form-table">
                    <tr>
                        <th><label for="project_name">Nama Project</label></th>
                        <td><input type="text" name="project_name" id="project_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="interval_minutes">Interval (menit)</label></th>
                        <td><input type="number" name="interval_minutes" id="interval_minutes" value="5" min="1"></td>
                    </tr>
                    <tr>
    <th><label for="thumbnail_id">Thumbnail</label></th>
    <td>
        <input type="hidden" name="thumbnail_id" id="thumbnail_id" value="">
        <button type="button" class="button" id="choose_thumbnail">Pilih Gambar</button>
        <span id="thumb_preview" style="margin-left:10px;"></span>
    </td>
</tr>

<tr>
    <th><label for="category_id">Kategori</label></th>
    <td>
        <?php
        wp_dropdown_categories(array(
            'show_option_none' => '-- Pilih Kategori --',
            'name' => 'category_id',
            'id' => 'category_id',
            'class' => 'regular-text',
            'hide_empty' => false,
        ));
        ?>
    </td>
</tr>

<tr>
    <th><label for="tags">Tag</label></th>
    <td><input type="text" name="tags" id="tags" class="regular-text" placeholder="Pisahkan dengan koma"></td>
</tr>

<tr>
    <th><label for="title_template">Template Judul</label></th>
    <td><input type="text" name="title_template" id="title_template" class="regular-text" placeholder="Contoh: Artikel {{nama}}"></td>
</tr>

<tr>
    <th><label for="content_template">Template Konten</label></th>
    <td>
        <textarea name="content_template" id="content_template" rows="6" class="large-text" placeholder="Contoh: Halo {{nama}}, ini artikel tentang {{topik}}."></textarea>
    </td>
</tr>

                    <tr>
                        <th><label for="csv_file">File CSV</label></th>
                        <td><input type="file" name="csv_file" id="csv_file" accept=".csv" required></td>
                    </tr>
                </table>

                <p><button type="submit" class="button button-primary">Upload & Proses</button></p>
            </form>

            <?php if ($project): ?>
                <hr>
                <h2>Task & Log Project #<?= intval($project->id); ?></h2>
                <?php $this->render_project_tasks($project->id); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render daftar task milik 1 project.
     */
    private function render_project_tasks($project_id){
        global $wpdb;
        $tasks_tbl = $wpdb->prefix.'wpap_tasks';
        $logs_tbl = $wpdb->prefix.'wpap_logs';

        $tasks = $wpdb->get_results($wpdb->prepare("SELECT * FROM $tasks_tbl WHERE project_id=%d ORDER BY id DESC LIMIT 100", $project_id));
        $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $logs_tbl WHERE project_id=%d ORDER BY id DESC LIMIT 50", $project_id));
        ?>
        <h3>Daftar Task</h3>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>Row</th><th>Status</th><th>Dijadwalkan</th><th>Update</th><th>Error</th></tr></thead>
            <tbody>
            <?php if ($tasks): foreach($tasks as $t): ?>
                <tr>
                    <td><?= intval($t->id); ?></td>
                    <td><?= intval($t->row_index); ?></td>
                    <td><?= esc_html($t->status); ?></td>
                    <td><?= esc_html($t->scheduled_at); ?></td>
                    <td><?= esc_html($t->updated_at); ?></td>
                    <td><?= esc_html($t->last_error); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6"><em>Belum ada task.</em></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <h3>Log Terbaru</h3>
        <table class="widefat striped">
            <thead><tr><th>ID</th><th>Task</th><th>Pesan</th><th>Waktu</th></tr></thead>
            <tbody>
            <?php if ($logs): foreach($logs as $l): ?>
                <tr>
                    <td><?= intval($l->id); ?></td>
                    <td><?= intval($l->task_id); ?></td>
                    <td><?= esc_html($l->message); ?></td>
                    <td><?= esc_html($l->created_at); ?></td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4"><em>Tidak ada log.</em></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }
    /* -------------------------------------------------------------
     *  BAGIAN 4 — HANDLE CSV UPLOAD + BATCH PROCESSING
     * ------------------------------------------------------------- */

    /**
     * Menangani upload CSV dan menjadwalkan proses batch.
     */
    public function handle_csv_upload(){
        if (!current_user_can('manage_options')) wp_die(__('Tidak punya izin.'));
        check_admin_referer('wpap_upload_csv');
    
        // Pastikan file upload valid
        if (empty($_FILES['csv_file']['tmp_name']) || !file_exists($_FILES['csv_file']['tmp_name'])) {
            wp_die(__('File CSV tidak ditemukan.'));
        }
    
        // Gunakan handler WordPress untuk keamanan & kompatibilitas
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        $overrides = array('test_form' => false, 'mimes' => array('csv' => 'text/csv'));
        $upload = wp_handle_upload($_FILES['csv_file'], $overrides);
    
        if (isset($upload['error'])) {
            wp_die('Upload gagal: ' . esc_html($upload['error']));
        }
    
        $csv_path = $upload['file'];
        global $wpdb;
        $projects_tbl = $wpdb->prefix . 'wpap_projects';
    
        // Ambil input tambahan dari form
        $project_name = sanitize_text_field($_POST['project_name']);
        $interval = intval($_POST['interval_minutes']);
        $thumbnail_id = isset($_POST['thumbnail_id']) ? intval($_POST['thumbnail_id']) : 0;
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $tags = sanitize_text_field($_POST['tags']);
        $title_template = wp_kses_post($_POST['title_template']);
        $content_template = wp_kses_post($_POST['content_template']);
    
        // Simpan project baru ke database
        $wpdb->insert($projects_tbl, array(
            'name' => $project_name,
            'csv_path' => $csv_path,
            'interval_minutes' => $interval,
            'thumbnail_id' => $thumbnail_id,
            'category_id' => $category_id,
            'tags' => $tags,
            'title_template' => $title_template,
            'content_template' => $content_template,
            'created_at' => current_time('mysql'),
            'status' => 'waiting'
        ));
        $project_id = $wpdb->insert_id;
    
        // Log awal
        $this->log_msg($project_id, 0, "Upload CSV berhasil untuk project '$project_name'. Mulai proses batch pertama.");
    
        // Jadwalkan batch pertama untuk proses CSV
        $batch_size = $this->default_batch_size;
        $start_delay = 5; // detik
    
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + $start_delay, 'wpap_process_csv_batch', array(
                'project_id' => $project_id,
                'offset' => 0,
                'batch_size' => $batch_size
            ), 'wp-auto-posts');
        } else {
            wp_schedule_single_event(time() + $start_delay, 'wpap_process_csv_batch', array($project_id, 0, $batch_size));
        }
    
        // Redirect ke halaman project detail
        wp_redirect(admin_url('admin.php?page=wpap-new-project&project_id=' . $project_id));
        exit;
    }
    

    /* -------------------------------------------------------------
     *  BAGIAN 5 — EKSEKUSI TASK + LOGGING + AJAX HANDLER
     * ------------------------------------------------------------- */

    /**
     * Menjalankan satu task (dipanggil via Action Scheduler / WP-Cron)
     */
    public function run_task($task_id){
        global $wpdb;
        $tasks_tbl = $wpdb->prefix . 'wpap_tasks';
        $projects_tbl = $wpdb->prefix . 'wpap_projects';
    
        $task = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tasks_tbl WHERE id=%d", $task_id));
        if (!$task) return;
    
        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $projects_tbl WHERE id=%d", $task->project_id));
        if (!$project) return;
    
        $data = maybe_unserialize($task->data);
        if (!is_array($data)) return;
    
        // pastikan tidak menjalankan dua kali
        if ($task->status === 'done') return;
    
        // replace variabel {{kolom}} di title & content
        $title = $this->replace_vars($project->title_template, $data);
        $content = $this->replace_vars($project->content_template, $data);
    
        // fallback jika title kosong
        if (empty($title)) $title = 'Posting ' . $task_id;
    
        $new_post = array(
            'post_title'   => wp_strip_all_tags($title),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_author'  => get_current_user_id(),
            'post_category'=> !empty($project->category_id) ? array(intval($project->category_id)) : array(),
        );
    
        $post_id = wp_insert_post($new_post, true);
    
        if (is_wp_error($post_id)) {
            $this->log_msg($task->project_id, $task_id, "Gagal membuat posting: " . $post_id->get_error_message());
            $wpdb->update($tasks_tbl, array('status' => 'failed', 'updated_at' => current_time('mysql')), array('id' => $task_id));
            return;
        }
    
        // Tambahkan tag jika ada
        if (!empty($project->tags)) {
            $tags_array = array_map('trim', explode(',', $project->tags));
            wp_set_post_tags($post_id, $tags_array, false);
        }
    
        // Tambahkan featured image (thumbnail)
        if (!empty($project->thumbnail_id)) {
            set_post_thumbnail($post_id, intval($project->thumbnail_id));
        }
    
        // Tandai task selesai
        $wpdb->update($tasks_tbl, array(
            'status' => 'done',
            'updated_at' => current_time('mysql')
        ), array('id' => $task_id));
    
        $this->log_msg($task->project_id, $task_id, "Posting berhasil dibuat: #{$post_id} ({$title})");
    }
    


    /**
     * Mengecek tasks waiting dan menjalankannya jika waktunya tiba
     */
    public function check_and_schedule_waiting() {
        global $wpdb;
        $tasks_tbl = $wpdb->prefix . 'wpap_tasks';
        $now = current_time('mysql');

        $tasks = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM $tasks_tbl 
            WHERE status='waiting' AND scheduled_at <= %s 
            ORDER BY scheduled_at ASC LIMIT 5
        ", $now));

        if (!$tasks) return;

        foreach ($tasks as $t) {
            $run_ts = time() + 5;
            $this->schedule_task($t->id, $run_ts);
        }
    }

    /**
     * Fungsi umum untuk menjadwalkan task (Action Scheduler / WP-Cron)
     */
    private function schedule_task($task_id, $timestamp = 0, $project_id = 0) {
        global $wpdb;
        $tasks_tbl = $wpdb->prefix . 'wpap_tasks';
        $projects_tbl = $wpdb->prefix . 'wpap_projects';
    
        if (!$timestamp || !$project_id) {
            // fallback ambil dari task
            $task = $wpdb->get_row($wpdb->prepare("SELECT project_id FROM $tasks_tbl WHERE id=%d", $task_id));
            if ($task) {
                $project_id = intval($task->project_id);
            }
            $timestamp = $timestamp ?: (time() + 5);
        }
    
        // ambil interval dari project
        $interval = max(1, intval($wpdb->get_var($wpdb->prepare(
            "SELECT interval_minutes FROM $projects_tbl WHERE id=%d", $project_id
        ))));
    
        // hitung penjadwalan: task berikutnya sesuai interval
        $last_task_time = $wpdb->get_var($wpdb->prepare("
            SELECT MAX(scheduled_at) FROM $tasks_tbl WHERE project_id=%d", $project_id
        ));
        if ($last_task_time) {
            $timestamp = max($timestamp, strtotime($last_task_time) + ($interval * 60));
        }
    
        // schedule
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action($timestamp, 'wpap_run_task', array('task_id' => $task_id), 'wp-auto-posts');
        } else {
            wp_clear_scheduled_hook('wpap_run_task', array($task_id));
            wp_schedule_single_event($timestamp, 'wpap_run_task', array($task_id));
        }
    
        // update waktu ke DB
        $wpdb->update($tasks_tbl, array(
            'scheduled_at' => date('Y-m-d H:i:s', $timestamp),
            'updated_at'   => current_time('mysql')
        ), array('id' => $task_id));
    }
    

    /**
     * Mencatat log ke database
     */
    private function log_msg($project_id, $task_id, $message) {
        global $wpdb;
        $logs_tbl = $wpdb->prefix . 'wpap_logs';
        $wpdb->insert($logs_tbl, array(
            'task_id' => intval($task_id),
            'project_id' => intval($project_id),
            'message' => sanitize_text_field($message),
            'created_at' => current_time('mysql')
        ));
    }

    /**
     * AJAX: Requeue semua task failed
     */
    public function ajax_requeue() {
        check_ajax_referer('wpap_requeue');
        if (!current_user_can('manage_options')) wp_send_json_error('Tidak diizinkan');

        global $wpdb;
        $project_id = intval($_POST['project_id']);
        $tasks_tbl = $wpdb->prefix . 'wpap_tasks';

        $wpdb->query($wpdb->prepare("
            UPDATE $tasks_tbl 
            SET status='waiting', last_error=NULL, updated_at=%s 
            WHERE project_id=%d AND status='failed'
        ", current_time('mysql'), $project_id));

        wp_send_json_success('Task gagal telah di-requeue ulang.');
    }

    /**
     * AJAX: Force run semua task waiting segera
     */
    public function ajax_force_run_project() {
        check_ajax_referer('wpap_force_run');
        if (!current_user_can('manage_options')) wp_send_json_error('Tidak diizinkan');

        global $wpdb;
        $project_id = intval($_POST['project_id']);
        $tasks_tbl = $wpdb->prefix . 'wpap_tasks';
        $projects_tbl = $wpdb->prefix . 'wpap_projects';

        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $projects_tbl WHERE id=%d", $project_id));
        if (!$project) wp_send_json_error('Project tidak ditemukan.');

        $tasks = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM $tasks_tbl 
            WHERE project_id=%d AND status='waiting'
            ORDER BY id ASC
        ", $project_id));

        if (!$tasks) wp_send_json_success('Tidak ada task waiting.');

        $interval = max(1, intval($project->interval_minutes));
        $now = time();
        $i = 0;

        foreach ($tasks as $t) {
            $scheduled_ts = $now + ($i * $interval * 60);
            $this->schedule_task($t->id, $scheduled_ts);

            $wpdb->update($tasks_tbl, array(
                'scheduled_at' => date('Y-m-d H:i:s', $scheduled_ts),
                'updated_at' => current_time('mysql')
            ), array('id' => $t->id));
            $i++;
        }

        wp_send_json_success("Semua task waiting telah dijadwalkan ulang ($interval menit antar task).");
    }
    /* -------------------------------------------------------------
     *  BAGIAN 6 — UTILS FINAL: HEADER MAPPING, replace_vars, INISIALISASI
     * ------------------------------------------------------------- */

    /**
     * Baca header CSV dari file (baris pertama) dan kembalikan array header.
     * Mengembalikan array kosong jika gagal.
     */
    private function read_csv_headers($file){
        if (!file_exists($file)) return array();
        $h = fopen($file, 'r');
        if (!$h) return array();
        $header = fgetcsv($h);
        fclose($h);
        if (!$header) return array();
        // normalisasi header: trim, ubah spasi/karakter jadi underscore, lowercase
        $cols = array();
        foreach($header as $c){
            $c = trim($c);
            $c = preg_replace('/[^a-zA-Z0-9_]/', '_', $c);
            $c = strtolower($c);
            $cols[] = $c;
        }
        return $cols;
    }

    /**
     * Mapping row numeric index -> associative berdasarkan $headers.
     * Jika headers jumlahnya kurang, sisanya diberi key numeric.
     */
    private function map_row_with_headers($row, $headers){
        $out = array();
        for ($i = 0; $i < count($row); $i++){
            if (isset($headers[$i]) && $headers[$i] !== ''){
                $out[$headers[$i]] = isset($row[$i]) ? sanitize_text_field($row[$i]) : '';
            } else {
                $out['col_'.$i] = isset($row[$i]) ? sanitize_text_field($row[$i]) : '';
            }
        }
        return $out;
    }

    /**
     * Perubahan: Versi process_csv_batch yang sudah diperbaiki untuk membaca header
     * dan menyimpan data tasks sebagai associative array (key berdasarkan header CSV).
     *
     * Parameter bisa dipanggil sebagai:
     * - AS: array('project_id'=>..., 'offset'=>..., 'batch_size'=>...)
     * - WP-Cron positional: ($project_id, $offset, $batch_size)
     */
    public function process_csv_batch($arg1 = null, $arg2 = 0, $arg3 = null){
        // normalize args
        if (is_array($arg1) && isset($arg1['project_id'])) {
            $project_id = intval($arg1['project_id']);
            $offset = intval($arg1['offset']);
            $batch_size = intval($arg1['batch_size']);
        } else {
            $project_id = intval($arg1);
            $offset = intval($arg2);
            $batch_size = $arg3 ? intval($arg3) : $this->default_batch_size;
        }
        if ($batch_size <= 0) $batch_size = $this->default_batch_size;

        global $wpdb;
        $projects_tbl = $wpdb->prefix . 'wpap_projects';
        $tasks_tbl = $wpdb->prefix . 'wpap_tasks';

        $project = $wpdb->get_row($wpdb->prepare("SELECT * FROM $projects_tbl WHERE id=%d", $project_id));
        if (!$project) return;

        $file = $project->csv_path;
        if (!file_exists($file)) {
            $this->log_msg($project_id, 0, "File CSV tidak ditemukan: " . $file);
            return;
        }

        $handle = fopen($file, 'r');
        if (!$handle) {
            $this->log_msg($project_id, 0, "Gagal membuka file CSV.");
            return;
        }

        // baca header (baris pertama) dan normalisasi
        $headers = $this->read_csv_headers($file);
        if (empty($headers)) {
            // jika tidak ada header, coba gunakan numeric keys nanti
            $this->log_msg($project_id, 0, "Header CSV tidak ditemukan atau kosong — akan menggunakan kolom numerik.");
        }

        // lompat ke offset baris data (skip header)
        $current_row = 0;
        // jika offset = 0, kita harus memastikan file pointer sudah setelah header
        // kalau header ada, fgetcsv sudah diambil oleh read_csv_headers; kita still need to reopen
        // Kita sudah mere-open file, jadi jika headers ada, ambil dan lewati header sekali.
        if (!empty($headers)){
            // pointer saat ini berada di awal file; ambil header sekali
            $h_header = fgetcsv($handle);
            $current_row = 0;
        }

        // skip sampai offset
        while ($current_row < $offset && fgetcsv($handle) !== false) {
            $current_row++;
        }

        $processed = 0;
        $skipped = 0;

        $now = time();
        $interval = max(1, intval($project->interval_minutes));
        // scheduled start: jika project belum pernah dijadwalkan, mulai dari sekarang+5
        $next_time = $now + 5;

        // Untuk menghitung scheduled_at berurutan berdasarkan row_index terakhir jika ada:
        $last_sched = $wpdb->get_var($wpdb->prepare("SELECT scheduled_at FROM $tasks_tbl WHERE project_id=%d ORDER BY id DESC LIMIT 1", $project_id));
        if ($last_sched) {
            $lt = strtotime($last_sched);
            if ($lt !== false && $lt > $next_time) $next_time = $lt + ($interval * 60);
        }

        while (($row = fgetcsv($handle)) !== false) {
            $current_row++;
            // jika baris kosong semua, skip
            $all_empty = true;
            foreach ($row as $c) { if (trim($c) !== '') { $all_empty = false; break; } }
            if ($all_empty) { $skipped++; continue; }

            // mapping ke associative array jika header ada, else gunakan numeric keys 'col_n'
            if (!empty($headers)) {
                $mapped = $this->map_row_with_headers($row, $headers);
            } else {
                // numeric keys
                $mapped = array();
                foreach ($row as $i => $v) $mapped['col_'.$i] = sanitize_text_field($v);
            }

            // tambahkan metadata default project (jika ada)
            $mapped['_thumbnail_id'] = isset($project->thumbnail_id) ? intval($project->thumbnail_id) : 0;
            $mapped['_category_id'] = isset($project->category_id) ? intval($project->category_id) : 0;
            $mapped['_tags'] = isset($project->tags) ? $project->tags : '';
            $mapped['_title_template'] = isset($project->title_template) ? $project->title_template : '';
            $mapped['_content_template'] = isset($project->content_template) ? $project->content_template : '';

            $scheduled_at = date('Y-m-d H:i:s', $next_time);

            $wpdb->insert($tasks_tbl, array(
                'project_id' => $project_id,
                'row_index' => $current_row,
                'data' => maybe_serialize($mapped),
                'scheduled_at' => $scheduled_at,
                'status' => 'waiting',
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ));
            $task_id = $wpdb->insert_id;

            // schedule task
            $this->schedule_task($task_id, $next_time);

            $next_time += $interval * 60;
            $processed++;

            if ($processed >= $batch_size) break;
        }

        // Check EOF
        $eof = feof($handle);
        fclose($handle);

        $this->log_msg($project_id, 0, "Batch offset {$offset} selesai. Diproses: {$processed}, Dilewati: {$skipped}.");

        if (!$eof) {
            // schedule next batch
            $next_offset = $offset + $processed;
            if (function_exists('as_schedule_single_action')) {
                as_schedule_single_action(time() + 5, 'wpap_process_csv_batch', array(
                    'project_id' => $project_id,
                    'offset' => $next_offset,
                    'batch_size' => $batch_size
                ), 'wp-auto-posts');
            } else {
                wp_schedule_single_event(time() + 10, 'wpap_process_csv_batch', array($project_id, $next_offset, $batch_size));
            }
        } else {
            // selesai: update status proyek
            $wpdb->update($projects_tbl, array('status' => 'ready'), array('id' => $project_id));
            $this->log_msg($project_id, 0, "Proyek #$project_id: semua task CSV berhasil dibuat (total terakhir offset: $offset, processed: $processed).");
        }
    }

    /**
     * Gantikan variabel di template: mendukung {{nama}} dan lainnya.
     * Jika $escape_title true maka akan strip tags (untuk title).
     * Menerima $data associative array (key->value).
     */
    private function replace_vars($template, $data, $escape_title = false){
        $template = (string)$template;
        if (empty($template)) return $template;
        // cari semua {{ var }}
        preg_match_all('/{{\s*([a-zA-Z0-9_]+)\s*}}/', $template, $matches);
        if (empty($matches[1])) return $template;
        foreach($matches[1] as $var){
            $val = '';
            if (is_array($data) && isset($data[$var])) $val = $data[$var];
            // fallback: cari 'col_n' jika var numeric-like
            if ($val === '' && preg_match('/^col_(\d+)$/', $var, $m)){
                $idx = intval($m[1]);
                if (is_array($data) && isset($data['col_'.$idx])) $val = $data['col_'.$idx];
            }
            $val = $escape_title ? wp_strip_all_tags($val) : wp_kses_post($val);
            $template = str_replace('{{'.$var.'}}', $val, $template);
        }
        return $template;
    }

    /* --------------------------------------
 * ADMIN: daftar semua project
 * -------------------------------------- */
public function page_projects_list(){
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $projects_tbl = $wpdb->prefix . 'wpap_projects';
    $tasks_tbl    = $wpdb->prefix . 'wpap_tasks';

    $projects = $wpdb->get_results("SELECT * FROM $projects_tbl ORDER BY id DESC");
    ?>
    <div class="wrap">
        <h1>Daftar Project Auto Posts</h1>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nama Project</th>
                    <th>Status</th>
                    <th>Total Task</th>
                    <th>Selesai</th>
                    <th>Progress</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($projects)) : ?>
                    <tr><td colspan="7" style="text-align:center;">Belum ada project</td></tr>
                <?php else : ?>
                    <?php foreach ($projects as $p) : 
                        $total   = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tasks_tbl WHERE project_id=%d", $p->id));
                        $done    = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tasks_tbl WHERE project_id=%d AND status='done'", $p->id));
                        $failed  = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tasks_tbl WHERE project_id=%d AND status='failed'", $p->id));
                        $total   = max(1, intval($total));
                        $percent = round(($done / $total) * 100, 1);
                        ?>
                        <tr>
                            <td><?php echo intval($p->id); ?></td>
                            <td><?php echo esc_html($p->name); ?></td>
                            <td><?php echo esc_html($p->status); ?></td>
                            <td><?php echo $total; ?></td>
                            <td><?php echo $done; ?></td>
                            <td>
                                <div style="background:#e5e5e5;border-radius:3px;overflow:hidden;width:120px;">
                                    <div style="background:#46b450;width:<?php echo $percent; ?>%;height:10px;"></div>
                                </div>
                                <small><?php echo $percent; ?> %</small>
                            </td>
                            <td>
                                <button class="button button-small wpap-force-run" 
                                        data-id="<?php echo $p->id; ?>">
                                    Jalankan Sekarang
                                </button>
                                <button class="button button-small wpap-requeue-failed" 
                                        data-id="<?php echo $p->id; ?>">
                                    Requeue Failed
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}


} // akhir class WP_Auto_Posts_V05


// Inisialisasi plugin utama
WP_Auto_Posts_V05::instance();

/**
 * Saat plugin diaktifkan, pastikan folder /assets dan file default ada.
 * File hanya akan dibuat jika belum ada (tidak menimpa file manual).
 */
register_activation_hook(__FILE__, function(){
    $dir = plugin_dir_path(__FILE__) . 'assets';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    // ===== CSS: wpap-admin.css =====
    $css_content = <<<CSS
.wrap h1{margin-bottom:16px;} 
.wpap-preview{border:1px solid #eee;padding:8px;}
CSS;

    if (!file_exists($dir . '/wpap-admin.css')) {
        file_put_contents($dir . '/wpap-admin.css', $css_content);
    }

    // ===== JS: wpap-admin.js =====
    $js_content = <<<JS
jQuery(document).ready(function($){ console.log('WPAP v0.5 admin loaded'); });
jQuery(document).ready(function ($) {
    // === THUMBNAIL PICKER ===
    let wpapMediaFrame;

    $('#choose_thumbnail').on('click', function (e) {
        e.preventDefault();

        // Jika sudah ada frame, buka ulang
        if (wpapMediaFrame) {
            wpapMediaFrame.open();
            return;
        }

        // Buat media frame baru
        wpapMediaFrame = wp.media({
            title: 'Pilih Gambar Thumbnail',
            button: { text: 'Gunakan Gambar Ini' },
            multiple: false
        });

        // Ketika gambar dipilih
        wpapMediaFrame.on('select', function () {
            const attachment = wpapMediaFrame.state().get('selection').first().toJSON();
            $('#thumbnail_id').val(attachment.id);
            $('#thumb_preview').html('<img src="' + attachment.url + '" style="max-height:60px;border:1px solid #ccc;margin-top:5px;">');
        });

        wpapMediaFrame.open();
    });
});

// === PROJECT LIST ACTIONS ===
jQuery(document).ready(function ($) {
    $('.wpap-force-run').on('click', function () {
        if (!confirm(WPAP_Admin.i18n_confirm_force)) return;
        const id = $(this).data('id');
        $.post(WPAP_Admin.ajax_url, {
            action: 'wpap_force_run_project',
            nonce: WPAP_Admin.nonce_force_run,
            project_id: id
        }, function (res) {
            alert(res.data || 'OK');
            location.reload();
        });
    });

    $('.wpap-requeue-failed, .wpap-requeue').on('click', function () {
        if (!confirm(WPAP_Admin.i18n_confirm_requeue)) return;
        const id = $(this).data('id');
        $.post(WPAP_Admin.ajax_url, {
            action: 'wpap_requeue',
            nonce: WPAP_Admin.nonce_requeue,
            project_id: id
        }, function (res) {
            alert(res.data || 'OK');
            location.reload();
        });
    });
});
JS;

    if (!file_exists($dir . '/wpap-admin.js')) {
        file_put_contents($dir . '/wpap-admin.js', $js_content);
    }
});
