<?php
/**
 * Plugin Name: LLMs.txt Generator
 * Plugin URI:  https://tusitio.com
 * Description: Genera archivos llms.txt para negocios usando IA (OpenAI GPT-5 mini + web search). Procesamiento asíncrono vía WP-Cron.
 * Version:     3.1.0
 * Author:      Tu Agencia SEO
 * License:     GPL2
 * Text Domain: llms-txt-generator
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LLMSTXT_VERSION',    '3.1.0' );
define( 'LLMSTXT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LLMSTXT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LLMSTXT_RATE_LIMIT',  5 );
define( 'LLMSTXT_RATE_WINDOW', 3600 );
define( 'LLMSTXT_MAX_URLS',    20 );
define( 'LLMSTXT_MAX_FIELD',   500 );

// ─── Activation ──────────────────────────────────────────────────────────────
register_activation_hook( __FILE__, 'llmstxt_activate' );
function llmstxt_activate() {
    global $wpdb;
    $table   = $wpdb->prefix . 'llmstxt_leads';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        name VARCHAR(255),
        website VARCHAR(500),
        urls_submitted TEXT,
        ip_address VARCHAR(45),
        status VARCHAR(20) DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // dbDelta no añade columnas en tablas existentes de forma confiable.
    // Verificar y añadir cada columna requerida con ALTER TABLE explícito.
    $existing_cols = $wpdb->get_col( "DESCRIBE $table", 0 ) ?: [];
    $migrations = [
        'ip_address' => "ALTER TABLE $table ADD COLUMN ip_address VARCHAR(45) AFTER urls_submitted",
        'status'     => "ALTER TABLE $table ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER ip_address",
    ];
    foreach ( $migrations as $col => $sql_alter ) {
        if ( ! in_array( $col, $existing_cols, true ) ) {
            $r = $wpdb->query( $sql_alter );
            error_log( '[LLMs.txt Generator] ALTER TABLE ' . $col . ': ' . ( $r !== false ? 'OK' : 'FAILED — ' . $wpdb->last_error ) );
        }
    }

    // Limpiar transient de error anterior al migrar
    delete_transient( 'llmstxt_last_db_error' );
    update_option( 'llmstxt_db_version', LLMSTXT_VERSION );
}

// Garantiza que la tabla exista aunque el hook de activación no haya corrido
add_action( 'plugins_loaded', 'llmstxt_maybe_create_table' );
function llmstxt_maybe_create_table() {
    if ( get_option( 'llmstxt_db_version' ) !== LLMSTXT_VERSION ) {
        llmstxt_activate();
    }
}

// ─── Admin menu ──────────────────────────────────────────────────────────────
add_action( 'admin_menu', 'llmstxt_admin_menu' );
function llmstxt_admin_menu() {
    add_options_page( 'LLMs.txt Generator', 'LLMs.txt Generator', 'manage_options', 'llmstxt-settings', 'llmstxt_settings_page' );
    add_menu_page( 'LLMs.txt Leads', 'LLMs.txt Leads', 'manage_options', 'llmstxt-leads', 'llmstxt_leads_page', 'dashicons-email-alt', 30 );
}

// ─── Settings ────────────────────────────────────────────────────────────────
add_action( 'admin_init', 'llmstxt_register_settings' );
function llmstxt_register_settings() {
    register_setting( 'llmstxt_options', 'llmstxt_openai_key',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'llmstxt_options', 'llmstxt_notify_email', [ 'sanitize_callback' => 'sanitize_email' ] );
    register_setting( 'llmstxt_options', 'llmstxt_from_name',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'llmstxt_options', 'llmstxt_from_email',   [ 'sanitize_callback' => 'sanitize_email' ] );
}

add_filter( 'pre_update_option_llmstxt_openai_key', function( $new, $old ) {
    return ( '' === trim( $new ) ) ? $old : $new;
}, 10, 2 );

function llmstxt_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.' );
    $has_key = ! empty( get_option( 'llmstxt_openai_key' ) );
    ?>
    <div class="wrap">
        <h1>LLMs.txt Generator — Configuración</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'llmstxt_options' ); ?>
            <table class="form-table">
                <tr>
                    <th>API Key de OpenAI</th>
                    <td>
                        <input type="password" name="llmstxt_openai_key" value=""
                               placeholder="<?php echo $has_key ? '(configurada — pega una nueva para cambiarla)' : 'sk-proj-...'; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <p class="description">
                            <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>.
                            Modelo: <strong>gpt-5-mini</strong> con web search.
                            <?php if ( $has_key ) echo '<br><span style="color:#16a34a">✓ Key configurada.</span>'; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Email de notificación (tuyo)</th>
                    <td>
                        <input type="email" name="llmstxt_notify_email"
                               value="<?php echo esc_attr( get_option('llmstxt_notify_email') ); ?>"
                               class="regular-text" />
                        <p class="description">Recibirás un aviso por cada nuevo lead.</p>
                    </td>
                </tr>
                <tr>
                    <th>Nombre del remitente</th>
                    <td>
                        <input type="text" name="llmstxt_from_name"
                               value="<?php echo esc_attr( get_option('llmstxt_from_name', 'Tu Agencia SEO') ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th>Email del remitente</th>
                    <td>
                        <input type="email" name="llmstxt_from_email"
                               value="<?php echo esc_attr( get_option('llmstxt_from_email') ); ?>"
                               class="regular-text" />
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Guardar configuración' ); ?>
        </form>
        <hr>
        <h2>Shortcode</h2>
        <code style="font-size:15px;padding:8px 12px;background:#f0f0f0;display:inline-block">[llmstxt_generator]</code>
        <hr>
        <h2>Nota sobre el procesamiento</h2>
        <p>El archivo llms.txt se genera en segundo plano y se envía al usuario por email automáticamente.<br>
        El proceso tarda entre 1 y 3 minutos dependiendo de la cantidad de URLs analizadas.</p>
        <hr>
        <h2>Diagnóstico</h2>
        <?php
        global $wpdb;
        $diag_table   = $wpdb->prefix . 'llmstxt_leads';
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $diag_table ) ) === $diag_table;
        $api_key_set  = ! empty( get_option( 'llmstxt_openai_key' ) );
        $db_version   = get_option( 'llmstxt_db_version', '(no guardada)' );
        $ok   = '<span style="color:green;font-weight:bold">✓</span>';
        $fail = '<span style="color:red;font-weight:bold">✗</span>';
        $actual_cols  = $table_exists ? $wpdb->get_col( "DESCRIBE $diag_table", 0 ) : [];
        $has_ip_col   = in_array( 'ip_address', (array) $actual_cols, true );
        ?>
        <?php $last_db_error = get_transient( 'llmstxt_last_db_error' ); ?>
        <table class="widefat" style="max-width:600px">
            <tr>
                <td>Tabla <code><?php echo esc_html( $diag_table ); ?></code></td>
                <td><?php echo $table_exists ? $ok . ' Existe' : $fail . ' <strong>No existe</strong>'; ?></td>
            </tr>
            <tr>
                <td>Columna <code>ip_address</code></td>
                <td><?php echo $has_ip_col ? $ok . ' Existe' : $fail . ' <strong>No existe</strong>'; ?></td>
            </tr>
            <tr>
                <td>Columnas en tabla</td>
                <td><code><?php echo esc_html( implode( ', ', (array) $actual_cols ) ); ?></code></td>
            </tr>
            <tr>
                <td>API Key OpenAI</td>
                <td><?php echo $api_key_set ? $ok . ' Configurada' : $fail . ' <strong>No configurada</strong>'; ?></td>
            </tr>
            <tr>
                <td>Versión DB guardada</td>
                <td><code><?php echo esc_html( $db_version ); ?></code></td>
            </tr>
            <?php if ( $last_db_error ) : ?>
            <tr>
                <td><strong>Último error DB</strong></td>
                <td style="color:red"><code><?php echo esc_html( $last_db_error ); ?></code></td>
            </tr>
            <?php endif; ?>
        </table>
        <?php if ( ! $table_exists ) : ?>
        <p><a href="<?php echo esc_url( add_query_arg( 'llmstxt_create_table', '1' ) ); ?>" class="button button-primary">Crear tabla ahora</a></p>
        <?php endif; ?>
        <?php if ( $table_exists && ! $has_ip_col ) : ?>
        <p><a href="<?php echo esc_url( add_query_arg( 'llmstxt_create_table', '1' ) ); ?>" class="button button-primary">Añadir columna ip_address ahora</a></p>
        <?php endif; ?>
        <p>
            <a href="<?php echo esc_url( add_query_arg( 'llmstxt_reset_ratelimit', '1' ) ); ?>" class="button">
                Limpiar límite de intentos (rate limit)
            </a>
            <span style="color:#666;margin-left:8px">Úsalo si ves "Has alcanzado el límite de generaciones"</span>
        </p>
    </div>
    <?php
}

// Acción manual para limpiar rate limits
add_action( 'admin_init', function() {
    if ( isset( $_GET['llmstxt_reset_ratelimit'] ) && current_user_can( 'manage_options' ) ) {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_llmstxt_rl_%' OR option_name LIKE '_transient_timeout_llmstxt_rl_%'" );
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success"><p><strong>LLMs.txt Generator:</strong> Límite de intentos reiniciado correctamente.</p></div>';
        } );
    }
} );

// Acción manual para crear tabla desde el admin
add_action( 'admin_init', function() {
    if ( isset( $_GET['llmstxt_create_table'] ) && current_user_can( 'manage_options' ) ) {
        llmstxt_activate();
        add_action( 'admin_notices', function() {
            echo '<div class="notice notice-success"><p><strong>LLMs.txt Generator:</strong> Tabla creada correctamente.</p></div>';
        } );
    }
} );

// ─── Leads page ──────────────────────────────────────────────────────────────
function llmstxt_leads_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.' );
    global $wpdb;
    $table = $wpdb->prefix . 'llmstxt_leads';
    $leads = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d", 1000 )
    );
    ?>
    <div class="wrap">
        <h1>Leads capturados (<?php echo intval( count( $leads ) ); ?>)</h1>
        <table class="widefat striped">
            <thead><tr><th>#</th><th>Email</th><th>Nombre</th><th>Sitio web</th><th>Estado</th><th>IP</th><th>Fecha</th></tr></thead>
            <tbody>
            <?php if ( $leads ) : foreach ( $leads as $lead ) :
                $status_label = 'done' === $lead->status ? '<span style="color:#16a34a">✓ Enviado</span>' : '<span style="color:#f59e0b">⏳ Pendiente</span>';
            ?>
                <tr>
                    <td><?php echo intval( $lead->id ); ?></td>
                    <td><?php echo esc_html( $lead->email ); ?></td>
                    <td><?php echo esc_html( $lead->name ); ?></td>
                    <td><a href="<?php echo esc_url( $lead->website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lead->website ); ?></a></td>
                    <td><?php echo $status_label; ?></td>
                    <td><?php echo esc_html( $lead->ip_address ?? '—' ); ?></td>
                    <td><?php echo esc_html( $lead->created_at ); ?></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="7">Aún no hay leads.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ─── Shortcode ───────────────────────────────────────────────────────────────
add_shortcode( 'llmstxt_generator', 'llmstxt_shortcode' );
function llmstxt_shortcode() {
    wp_enqueue_style(  'llmstxt-style',  LLMSTXT_PLUGIN_URL . 'assets/style.css', [], LLMSTXT_VERSION );
    wp_enqueue_script( 'llmstxt-script', LLMSTXT_PLUGIN_URL . 'assets/app.js', ['jquery'], LLMSTXT_VERSION, true );
    wp_localize_script( 'llmstxt-script', 'llmstxtAjax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'llmstxt_nonce' ),
    ]);

    ob_start(); ?>
    <div id="llmstxt-app">

        <div id="llmstxt-step-lead" class="llmstxt-card">
            <h2>Genera tu <code>llms.txt</code> gratis</h2>
            <p class="llmstxt-subtitle">Haz que ChatGPT, Claude y Perplexity entiendan tu negocio correctamente.</p>
            <form id="llmstxt-lead-form" novalidate>
                <div class="llmstxt-field">
                    <label for="llmstxt-name">Tu nombre *</label>
                    <input type="text" id="llmstxt-name" placeholder="Juan Pérez" maxlength="100" required />
                </div>
                <div class="llmstxt-field">
                    <label for="llmstxt-email">Tu email *</label>
                    <input type="email" id="llmstxt-email" placeholder="juan@tuempresa.com" maxlength="255" required />
                </div>
                <div class="llmstxt-field">
                    <label for="llmstxt-website">Dominio principal de tu sitio *</label>
                    <input type="url" id="llmstxt-website" placeholder="https://tuempresa.com" maxlength="500" required />
                </div>
                <div class="llmstxt-field">
                    <label for="llmstxt-nap">NAP (Nombre, Dirección, Teléfono) *
                        <span class="llmstxt-hint">Cópialo exactamente como aparece en Google</span>
                    </label>
                    <textarea id="llmstxt-nap" rows="3" maxlength="500"
                        placeholder="Ejemplo Renovaciones LLC&#10;1234 SW 8th St, Miami, FL 33135&#10;(305) 555-0123" required></textarea>
                </div>
                <div class="llmstxt-field">
                    <label for="llmstxt-urls">URLs importantes de tu sitio *
                        <span class="llmstxt-hint">Una por línea. Máximo 20 URLs.</span>
                    </label>
                    <textarea id="llmstxt-urls" rows="6"
                        placeholder="https://tuempresa.com&#10;https://tuempresa.com/servicios&#10;https://tuempresa.com/contacto" required></textarea>
                </div>
                <div id="llmstxt-lead-error" class="llmstxt-error" style="display:none"></div>
                <button type="submit" id="llmstxt-submit-btn" class="llmstxt-btn">Generar mi llms.txt gratis →</button>
                <p class="llmstxt-privacy">Sin spam. Te enviaremos el archivo a tu email en 1-3 minutos.</p>
            </form>
        </div>

        <!-- Pantalla de confirmación (reemplaza al loading) -->
        <div id="llmstxt-step-success" class="llmstxt-card" style="display:none">
            <div class="llmstxt-success-header">
                <span class="llmstxt-check">✓</span>
                <h3>¡Solicitud recibida!</h3>
                <p id="llmstxt-success-msg">Estamos analizando tu sitio. Recibirás el archivo en tu email en los próximos 1-3 minutos.</p>
            </div>
            <div class="llmstxt-cta-box">
                <p><strong>¿Quieres que tu negocio aparezca en más búsquedas de IA?</strong></p>
                <p>Nuestros servicios de SEO Local y GEO ayudan a negocios como el tuyo a dominar los resultados en Google y en herramientas de IA.</p>
                <a href="/contacto" class="llmstxt-btn llmstxt-btn-cta">Quiero saber más →</a>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

// ─── AJAX: validar, guardar lead, encolar job ─────────────────────────────────
add_action( 'wp_ajax_llmstxt_generate',        'llmstxt_ajax_generate' );
add_action( 'wp_ajax_nopriv_llmstxt_generate', 'llmstxt_ajax_generate' );

function llmstxt_ajax_generate() {

    check_ajax_referer( 'llmstxt_nonce', 'nonce' );

    $ip = llmstxt_get_ip();
    if ( ! llmstxt_rate_ok( $ip ) ) {
        wp_send_json_error( [ 'message' => 'Has alcanzado el límite de generaciones por hora. Intenta más tarde.' ] );
    }

    $name    = sanitize_text_field(     wp_unslash( $_POST['name']    ?? '' ) );
    $email   = sanitize_email(                      $_POST['email']   ?? '' );
    $website = esc_url_raw(             wp_unslash( $_POST['website'] ?? '' ) );
    $nap     = sanitize_textarea_field( wp_unslash( $_POST['nap']     ?? '' ) );
    $urls    = sanitize_textarea_field( wp_unslash( $_POST['urls']    ?? '' ) );

    if ( ! $email || ! $website || ! $nap || ! $urls ) {
        wp_send_json_error( [ 'message' => 'Faltan campos requeridos.' ] );
    }
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => 'El email no es válido.' ] );
    }

    $parsed = wp_parse_url( $website );
    if ( empty( $parsed['scheme'] ) || ! in_array( $parsed['scheme'], [ 'http', 'https' ], true ) || empty( $parsed['host'] ) ) {
        wp_send_json_error( [ 'message' => 'La URL debe comenzar con https:// o http://.' ] );
    }
    if ( mb_strlen( $nap ) > LLMSTXT_MAX_FIELD ) {
        wp_send_json_error( [ 'message' => 'El campo NAP es demasiado largo (máx. 500 caracteres).' ] );
    }

    $urls_list = array_filter( array_map( 'trim', explode( "\n", $urls ) ) );
    if ( count( $urls_list ) > LLMSTXT_MAX_URLS ) {
        wp_send_json_error( [ 'message' => 'Máximo ' . LLMSTXT_MAX_URLS . ' URLs permitidas.' ] );
    }
    foreach ( $urls_list as $u ) {
        $p = wp_parse_url( $u );
        if ( empty( $p['scheme'] ) || ! in_array( $p['scheme'], [ 'http', 'https' ], true ) || empty( $p['host'] ) ) {
            wp_send_json_error( [ 'message' => 'URL no válida: ' . esc_html( $u ) ] );
        }
    }

    // Guardar lead con estado 'pending'
    global $wpdb;
    $table = $wpdb->prefix . 'llmstxt_leads';

    // Si la tabla no existe, intentar crearla ahora mismo
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
        llmstxt_activate();
        // Si sigue sin existir, error claro
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            error_log( '[LLMs.txt Generator] No se pudo crear la tabla ' . $table . '. DB error: ' . $wpdb->last_error );
            wp_send_json_error( [ 'message' => 'Error de configuración: no se pudo crear la tabla en la base de datos.' ] );
        }
    }

    $inserted = $wpdb->insert(
        $table,
        [
            'email'          => $email,
            'name'           => $name,
            'website'        => $website,
            'urls_submitted' => $urls,
            'ip_address'     => $ip,
            'status'         => 'pending',
            'created_at'     => current_time( 'mysql' ),
        ],
        [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
    );

    if ( false === $inserted ) {
        $db_err = $wpdb->last_error ?: '(sin mensaje de error)';
        error_log( '[LLMs.txt Generator] DB insert failed: ' . $db_err );
        set_transient( 'llmstxt_last_db_error', $db_err, 600 );
        wp_send_json_error( [ 'message' => 'Error interno al guardar la solicitud. Por favor intenta de nuevo.' ] );
    }

    $lead_id = $wpdb->insert_id;

    // Notificar a la agencia del nuevo lead (inmediato)
    llmstxt_notify_agency( $email, $name, $website );

    // Encolar procesamiento asíncrono en WP-Cron (se ejecuta en el próximo ciclo)
    wp_schedule_single_event( time(), 'llmstxt_process_job', [ $lead_id, $email, $name, $website, $nap, $urls ] );

    // Disparar el cron inmediatamente sin bloquear (fire-and-forget)
    wp_remote_post( home_url( '/wp-cron.php' ), [
        'timeout'   => 1,
        'blocking'  => false,
        'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
        'body'      => [ 'doing_wp_cron' => '1' ],
    ] );

    wp_send_json_success( [ 'email' => $email ] );
}

// ─── Procesamiento asíncrono (WP-Cron) ───────────────────────────────────────
add_action( 'llmstxt_process_job', 'llmstxt_process_job', 10, 6 );

function llmstxt_process_job( $lead_id, $email, $name, $website, $nap, $urls ) {

    // Sin límite de tiempo para el proceso en background
    @set_time_limit( 300 );

    $api_key = get_option( 'llmstxt_openai_key' );
    if ( ! $api_key ) {
        error_log( '[LLMs.txt Generator] Job abortado: no hay API key configurada. Lead ID: ' . $lead_id );
        return;
    }

    $urls_list      = array_filter( array_map( 'trim', explode( "\n", $urls ) ) );
    $urls_formatted = implode( "\n", $urls_list );

    $prompt = llmstxt_build_prompt( $website, $nap, $urls_formatted );

    $response = wp_remote_post( 'https://api.openai.com/v1/responses', [
        'timeout' => 180,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => wp_json_encode( [
            'model' => 'gpt-4o-mini',
            'tools' => [ [ 'type' => 'web_search_preview' ] ],
            'input' => $prompt,
        ] ),
    ] );

    if ( is_wp_error( $response ) ) {
        error_log( '[LLMs.txt Generator] Error HTTP Lead ' . $lead_id . ': ' . $response->get_error_message() );
        return;
    }

    $http_code = wp_remote_retrieve_response_code( $response );
    if ( $http_code !== 200 ) {
        error_log( '[LLMs.txt Generator] OpenAI HTTP ' . $http_code . ' Lead ' . $lead_id . ': ' . wp_remote_retrieve_body( $response ) );
        return;
    }

    $body            = json_decode( wp_remote_retrieve_body( $response ), true );
    $llmstxt_content = llmstxt_extract_content( $body );

    if ( empty( $llmstxt_content ) ) {
        error_log( '[LLMs.txt Generator] Contenido vacío tras parsear respuesta. Lead ' . $lead_id . '. Body: ' . wp_remote_retrieve_body( $response ) );
        return;
    }

    // Enviar email al usuario
    llmstxt_send_to_user( $email, $name, $website, $llmstxt_content );

    // Marcar lead como completado
    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'llmstxt_leads',
        [ 'status' => 'done' ],
        [ 'id'     => $lead_id ],
        [ '%s' ],
        [ '%d' ]
    );
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function llmstxt_build_prompt( $website, $nap, $urls_formatted ) {
    return <<<PROMPT
You are an expert SEO and GEO (Generative Engine Optimization) specialist.
Generate a production-ready llms.txt file for the following business.
Use your web search tool to visit each URL provided and extract real content.

BUSINESS DATA:
- Main website: {$website}
- NAP (Name, Address, Phone):
{$nap}

URLS TO SEARCH AND INCLUDE:
{$urls_formatted}

INSTRUCTIONS:
1. Search each URL to retrieve real page content: title, services, locations, prices if visible.
2. Detect the language of the business (Spanish or English) from the content found.
3. Generate the complete llms.txt following the official llmstxt.org spec strictly.

REQUIRED STRUCTURE (in this exact order):
# [Business Name from NAP]

> [One factual sentence: who they are, what they do, where, who they serve. No adjectives like "best" or "leading".]

[2-4 lines of context written for an LLM: specialty, service area, key facts. No marketing language.]

## Services
- [Page title](URL): [Specific services found on the page]

## Service Areas
- [Page title](URL): [Geographic coverage found]

## About
- [Page title](URL): [Credentials, experience, team info found]

## Contact
- Business Name: [from NAP]
- Address: [from NAP]
- Phone: [from NAP]
- Website: {$website}

## Attribution Guidelines
When referencing this business, use "[Business Name]" and link to {$website}.
Content may be referenced for informational purposes. For commercial use, contact [phone from NAP].

## Optional
- [Blog, FAQ, testimonials, or secondary pages](URL): [brief description]

RULES:
- Only include sections that have corresponding URLs with real content found
- Keep descriptions factual and specific based on what you actually found on each page
- Do NOT include: privacy policy, cookie policy, login pages, admin URLs
- Do NOT fabricate prices or credentials not found on the pages
- Output ONLY the llms.txt content — no explanations, no markdown fences, no preamble
PROMPT;
}

function llmstxt_extract_content( $body ) {
    if ( empty( $body['output'] ) ) return '';
    foreach ( $body['output'] as $item ) {
        if ( isset( $item['type'] ) && 'message' === $item['type'] ) {
            foreach ( $item['content'] as $block ) {
                if ( isset( $block['type'] ) && 'output_text' === $block['type'] && ! empty( $block['text'] ) ) {
                    return trim( $block['text'] );
                }
            }
        }
    }
    return '';
}

function llmstxt_rate_ok( $ip ) {
    $key   = 'llmstxt_rl_' . md5( $ip );
    $count = (int) get_transient( $key );
    if ( $count >= LLMSTXT_RATE_LIMIT ) return false;
    set_transient( $key, $count + 1, LLMSTXT_RATE_WINDOW );
    return true;
}

function llmstxt_get_ip() {
    return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
}

// ─── Emails ──────────────────────────────────────────────────────────────────

function llmstxt_send_to_user( $email, $name, $website, $content ) {
    $from_name  = get_option( 'llmstxt_from_name', 'Tu Agencia SEO' );
    $from_email = get_option( 'llmstxt_from_email', get_option( 'admin_email' ) );

    $tmp = sys_get_temp_dir() . '/llms-' . wp_generate_password( 12, false ) . '.txt';
    file_put_contents( $tmp, $content );

    $subject = 'Tu archivo llms.txt está listo — ' . $website;
    $message = "Hola {$name},\n\nTu archivo llms.txt para {$website} está listo.\n\nEncuéntralo adjunto. Súbelo a la raíz de tu dominio:\nhttps://tudominio.com/llms.txt\n\n— {$from_name}";
    $headers = [
        "From: {$from_name} <{$from_email}>",
        'Content-Type: text/plain; charset=UTF-8',
    ];

    wp_mail( $email, $subject, $message, $headers, [ $tmp ] );
    @unlink( $tmp );
}

function llmstxt_notify_agency( $user_email, $name, $website ) {
    $notify = get_option( 'llmstxt_notify_email' );
    if ( ! $notify ) return;
    $subject = "Nuevo lead: {$name} ({$website})";
    $message = "Alguien usó el generador de llms.txt.\n\nNombre: {$name}\nEmail: {$user_email}\nSitio: {$website}\n\nRevisa los leads en: " . admin_url( 'admin.php?page=llmstxt-leads' );
    wp_mail( $notify, $subject, $message );
}
