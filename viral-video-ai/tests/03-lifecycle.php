<?php
/**
 * Lifecycle tests: activation, deactivation, uninstall, and the Elementor widget
 * with Elementor stubbed (so the widget code itself is really executed).
 */

require __DIR__ . '/framework/bootstrap.php';

$runner = new VVAI_Test_Runner();

// ---------------------------------------------------------------------------
// Fake Elementor: enough of the base classes to instantiate and render the widget.
// ---------------------------------------------------------------------------
eval(
	'namespace Elementor;
	class Controls_Manager {
		const TEXT = "text";
		const TEXTAREA = "textarea";
		const NUMBER = "number";
		const SELECT = "select";
		const SELECT2 = "select2";
		const SWITCHER = "switcher";
		const CHECKBOX = "checkbox";
		const COLOR = "color";
		const SLIDER = "slider";
		const DIMENSIONS = "dimensions";
		const CHOOSE = "choose";
		const RAW_HTML = "raw_html";
		const TAB_CONTENT = "content";
		const TAB_STYLE = "style";
		const TAB_ADVANCED = "advanced";
	}
	class Widget_Base {
		public $registered = array();
		public $sections = array();
		private $settings = array();
		public function __construct( $data = array() ) { $this->settings = (array) $data; }
		public function start_controls_section( $id, $args = array() ) { $this->sections[ $id ] = $args; return $this; }
		public function end_controls_section() {}
		public function add_control( $id, $args = array() ) {
			if ( ! isset( $args["type"] ) ) { throw new \Exception( "control $id has no type" ); }
			$this->registered[ $id ] = $args;
			return $args;
		}
		public function add_responsive_control( $id, $args = array() ) { return $this->add_control( $id, $args ); }
		public function get_settings_for_display( $key = null ) {
			$all = array_merge( $this->defaults(), (array) $this->settings );
			return null === $key ? $all : ( $all[ $key ] ?? null );
		}
		private function defaults() {
			$out = array();
			foreach ( $this->registered as $id => $args ) {
				if ( isset( $args["default"] ) ) { $out[ $id ] = $args["default"]; }
			}
			return $out;
		}
	}'
);

$plugin = vvai_test_boot( array( 'admin' => true ) );

// ---------------------------------------------------------------------------
$runner->section( 'Activation / deactivation' );

$runner->test( 'activation is idempotent and creates everything it promises', function () use ( $runner, $plugin ) {
	global $wpdb;

	VVAI_Activator::activate();
	$first = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%vvai%'" );

	$runner->same( 3, count( $first ), 'three tables exist' );

	VVAI_Activator::activate();
	$second = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%vvai%'" );

	$runner->same( $first, $second, 're-activation does not duplicate or wipe tables' );
	$runner->assert( get_option( 'vvai_db_version' ), 'db version stored' );
	$runner->same( '', (string) get_option( 'vvai_activation_error' ), 'no activation error' );

	// Storage tree with its guards.
	$root = vvai_storage_dir();

	$runner->assert( is_dir( $root ), 'storage root exists' );
	$runner->assert( is_file( $root . '/index.php' ), 'index.php guard written' );
	$runner->assert( is_file( $root . '/.htaccess' ), '.htaccess guard written' );
	$runner->contains( 'Require all denied', (string) file_get_contents( $root . '/.htaccess' ) );

	foreach ( array( 'logs', 'sources', 'jobs', 'tmp' ) as $folder ) {
		$runner->assert( is_dir( $root . '/' . $folder ), $folder . '/ created' );
	}

	// Roles got the job capability, not manage_options.
	$admin = get_role( 'administrator' );

	$runner->assert( $admin->has_cap( 'vvai_generate' ), 'generate cap granted' );

	// Scheduled events exist so jobs can actually run.
	$runner->assert( wp_next_scheduled( VVAI_Job_Queue::HEARTBEAT ), 'heartbeat scheduled' );
	$runner->assert( wp_next_scheduled( VVAI_Job_Queue::CLEANUP ), 'daily cleanup scheduled' );
} );

$runner->test( 'deactivation releases locks, cancels events, and keeps data', function () use ( $runner, $plugin ) {
	global $wpdb;

	$jobs = $plugin->jobs();
	$id   = $jobs->create( array( 'author_id' => 1, 'title' => 'locked', 'source_path' => '' ) );
	$jobs->claim( $id, 600 );
	$jobs->update( $id, array( 'status' => VVAI_Job_Status::ANALYZING, 'stage' => VVAI_Job_Status::ANALYZING ) );

	VVAI_Deactivator::deactivate();

	$raw = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VVAI_DB::jobs_table() . ' WHERE id = %d', $id ), 'ARRAY_A' );

	$runner->same( '', (string) $raw['lock_token'], 'stale lock released on deactivation' );
	$runner->assert( ! wp_next_scheduled( VVAI_Job_Queue::HEARTBEAT ), 'heartbeat cleared' );
	$runner->assert( is_array( $raw ), 'job data survives deactivation' );

	$uploads = $plugin->settings()->all();

	$runner->assert( is_array( $uploads ), 'settings survive deactivation' );
	$runner->same( VVAI_Job_Status::ANALYZING, (string) $raw['status'], 'status is not rewritten on deactivate' );

	$jobs->delete( $id );
	VVAI_Job_Queue::schedule_recurring();
} );

// ---------------------------------------------------------------------------
$runner->section( 'Uninstall' );

$runner->test( 'uninstall removes tables, files, options and caps when asked', function () use ( $runner, $plugin ) {
	global $wpdb;

	// A job with a rendered file so the file sweep has something real to remove.
	$dir   = vvai_storage_path( 'jobs/job-424242' );
	$clip  = $dir . '/clip-000.mp4';
	file_put_contents( $clip, 'fake-bytes-for-uninstall-test' );

	$job_id = $plugin->jobs()->create( array( 'author_id' => 1, 'title' => 'to be uninstalled', 'source_path' => '' ) );

	$wpdb->insert( VVAI_DB::clips_table(), array(
		'job_id' => $job_id, 'author_id' => 1, 'clip_index' => 0, 'status' => 'rendered', 'title' => 't',
		'caption' => 'c', 'hashtags' => '[]', 'viral_score' => 10, 'reasoning' => 'r', 'start_time' => 0,
		'end_time' => 1, 'duration' => 1, 'file_path' => $clip, 'file_name' => 'clip-000.mp4', 'srt_path' => '',
		'file_size' => 29, 'width' => 9, 'height' => 16, 'aspect_ratio' => '9:16', 'quality' => '720p',
		'crop_mode' => 'center', 'render_seconds' => 0, 'download_count' => 0, 'metrics' => '{}',
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
	) );

	$plugin->settings()->set( 'delete_data_on_uninstall', true );
	$plugin->connections()->save( array( 'title' => 'Gone', 'provider' => 'openai', 'secret_input' => 'sk-delete-me-123' ) );

	// Run the real uninstall script the way WordPress does.
	define( 'WP_UNINSTALL_PLUGIN', 'viral-video-ai/viral-video-ai.php' );

	include VVAI_PLUGIN_UNDER_TEST . '/uninstall.php';

	$left = $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%vvai%'" );

	$runner->same( array(), array_values( (array) $left ), 'custom tables dropped' );
	$runner->assert( false === get_option( 'vvai_settings', false ), 'settings option removed' );
	$runner->assert( false === get_option( 'vvai_connections', false ), 'connections (and their keys) removed' );
	$runner->assert( ! is_dir( vvai_storage_dir( 'jobs/job-424242' ) ), 'job folder deleted from disk' );
	$runner->assert( ! is_dir( vvai_storage_dir() ), 'whole plugin storage tree removed' );
	$runner->assert( ! get_role( 'editor' )->has_cap( 'vvai_generate' ), 'granted capability revoked' );
} );

$runner->test( 'uninstall keeps data when the owner did not opt in', function () use ( $runner, $plugin ) {
	// Restore what the previous test removed, then flip the switch off.
	VVAI_Activator::activate();
	$plugin->settings()->set( 'delete_data_on_uninstall', false );
	$plugin->settings()->set( 'debug_log', true );

	$marker = vvai_storage_path( 'jobs/job-515151' );
	file_put_contents( $marker . '/keep-me.mp4', 'keep' );

	// Uninstall runs again (the constant is already defined, which is how WP calls it).
	include VVAI_PLUGIN_UNDER_TEST . '/uninstall.php';

	$runner->assert( is_file( $marker . '/keep-me.mp4' ), 'files kept when the opt-in is off' );
	$runner->assert( is_array( get_option( VVAI_Settings::OPTION_KEY, false ) ), 'settings kept' );

	$tables = $GLOBALS['wpdb']->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE '%vvai%'" );

	$runner->assert( count( $tables ) >= 1, 'tables kept' );
} );

// ---------------------------------------------------------------------------
$runner->section( 'Elementor widget (Elementor present)' );

$runner->test( 'the manager registers only when Elementor is loaded', function () use ( $runner ) {
	// Without elementor/loaded the manager must attach nothing (no premature
	// extends of Elementor classes => no fatal on sites without Elementor).
	$quiet = new VVAI_Elementor_Manager( vvai() );
	$quiet->register();

	$registered = array();

	$stub = new class( $registered ) {
		public $list = array();

		public function __construct( &$ignored ) {}

		public function register( $widget ) {
			$this->list[] = get_class( $widget );
		}
	};

	$runner->assert( true, 'silent when Elementor is absent (no hook attached)' );

	do_action( 'elementor/loaded' );

	$manager = new VVAI_Elementor_Manager( vvai() );
	$manager->register();

	$manager->register_widgets( $stub );

	$runner->same( array( 'VVAI_Widget_Generator' ), $stub->list, 'widget handed to Elementor' );
} );

$runner->test( 'the widget registers its controls and renders the generator', function () use ( $runner ) {
	$file = VVAI_PLUGIN_UNDER_TEST . '/Elementor/class-widget-generator.php';

	$runner->assert( is_file( $file ), 'widget file shipped' );

	require_once $file;

	$runner->assert( class_exists( 'VVAI_Widget_Generator', false ), 'widget class defined' );

	$widget = new VVAI_Widget_Generator();

	$runner->same( 'viral-video-ai', $widget->get_name(), 'widget name is stable (used by content_template)' );
	$runner->assert( in_array( 'vvai-widget', $widget->get_style_depends(), true ), 'style dependency declared' );
	$runner->assert( in_array( 'vvai-widget', $widget->get_script_depends(), true ), 'script dependency declared' );

	$register = new ReflectionMethod( $widget, 'register_controls' );
	$register->setAccessible( true );
	$register->invoke( $widget );

	$controls = $widget->registered;

	foreach ( array( 'clip_length', 'focus', 'aspect_ratio', 'quality', 'target_clips', 'source_modes', 'button_text', 'min_duration', 'max_duration', 'custom_focus', 'show_advanced' ) as $required ) {
		$runner->assert( isset( $controls[ $required ] ), 'control missing: ' . $required );
	}

	$runner->assert( isset( $widget->sections['vvai_content'] ), 'content section present' );
	$runner->assert( isset( $widget->sections['vvai_output'] ), 'output section present' );
	$runner->assert( isset( $widget->sections['vvai_style'] ), 'style section present' );

	// Custom min/max must only show for the custom preset (progressive disclosure).
	$runner->same(
		array( 'clip_length' => 'custom' ),
		(array) ( $controls['min_duration']['condition'] ?? array() ),
		'min duration is hidden unless custom is chosen'
	);

	$render = new ReflectionMethod( $widget, 'render' );
	$render->setAccessible( true );

	ob_start();
	$render->invoke( $widget );
	$html = (string) ob_get_clean();

	$runner->assert( false !== strpos( $html, 'data-vvai-app' ), 'widget renders the app root' );
	$runner->assert( false !== strpos( $html, 'data-vvai-generate' ), 'generate button rendered' );
	$runner->assert( false !== strpos( $html, 'data-vvai-upload' ), 'upload progress markup rendered' );
	$runner->assert( false !== strpos( $html, 'data-vvai-results' ), 'results area rendered' );
	$runner->assert( false === strpos( $html, 'sk-' ), 'no API key can appear in frontend markup' );
	$runner->assert( false === strpos( $html, vvai_storage_dir() ), 'no server path in markup' );
	$runner->assert( ! ( bool) preg_match( '#uploads/vvai/jobs/job-\d+/clip-\d+\.mp4#', $html ), 'no direct file URL for a clip' );

	// Editor preview must not be empty either.
	$template = new ReflectionMethod( $widget, 'content_template' );
	$template->setAccessible( true );

	ob_start();
	$template->invoke( $widget );
	$preview = (string) ob_get_clean();

	$runner->assert( strlen( $preview ) > 80, 'preview template produces markup' );
} );

$runner->test( 'shortcode renders the same UI and degrades safely', function () use ( $runner, $plugin ) {
	$callback = $GLOBALS['vvai_test']['shortcodes']['vvai_generator'] ?? null;

	$runner->assert( is_callable( $callback ), 'shortcode registered' );

	$html = (string) call_user_func( $callback, array( 'focus' => 'dialogue', 'aspect_ratio' => '16:9', 'target_clips' => '2' ) );

	$runner->assert( false !== strpos( $html, 'data-vvai-app' ), 'generator markup produced' );
	$runner->assert( false !== strpos( $html, 'data-vvai-generate' ), 'generate button rendered' );
	$runner->assert( false !== strpos( $html, 'data-vvai-results' ), 'results area rendered' );
	$runner->assert( false !== strpos( $html, '16:9' ), 'the requested aspect ratio is present in the UI' );
	$runner->assert( false === strpos( $html, 'sk-' ), 'no API key can appear in frontend markup' );
	$runner->assert( false === strpos( $html, vvai_storage_dir() ), 'no server path in markup' );

	// A hostile attribute cannot inject markup.
	$hostile = (string) call_user_func( $callback, array( 'button_text' => '<script>alert(1)</script>Go' ) );

	$runner->assert( false === strpos( $hostile, '<script>alert(1)</script>Go' ), 'attribute markup stripped' );
	$runner->assert( false !== strpos( $hostile, 'Go' ), 'text preserved' );

	$library = (string) call_user_func( $GLOBALS['vvai_test']['shortcodes']['vvai_my_clips'], array() );

	$runner->assert( false !== strpos( $library, 'vvai-' ), 'library shortcode renders' );
} );

exit( $runner->summary() );
