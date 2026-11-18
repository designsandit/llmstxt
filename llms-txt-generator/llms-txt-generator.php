<?php
/**
 * Plugin Name: LLMs.txt Generator
 * Plugin URI:  https://tusitio.com
 * Description: Genera archivos llms.txt para negocios usando IA (OpenAI GPT-5 mini + web search). Procesamiento asíncrono vía WP-Cron.
 * Version:     3.4.0
 * Author:      Tu Agencia SEO
 * License:     GPL2
 * Text Domain: llms-txt-generator
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LLMSTXT_VERSION',       '3.4.0' );
define( 'LLMSTXT_PLUGIN_FILE',    __FILE__ );
define( 'LLMSTXT_PLUGIN_DIR',    plugin_dir_path( __FILE__ ) );
define( 'LLMSTXT_PLUGIN_URL',    plugin_dir_url( __FILE__ ) );
define( 'LLMSTXT_RATE_LIMIT',    5 );     // envíos exitosos por IP…
define( 'LLMSTXT_RATE_WINDOW',   3600 );  // …por esta ventana (segundos)
define( 'LLMSTXT_MAX_URLS',      20 );
define( 'LLMSTXT_MAX_FIELD',     500 );
define( 'LLMSTXT_DEFAULT_MODEL', 'gpt-5-mini' );
define( 'LLMSTXT_API_TIMEOUT',   240 );   // segundos de espera a OpenAI
define( 'LLMSTXT_CRON_HOOK',     'llmstxt_process_job' );

require_once LLMSTXT_PLUGIN_DIR . 'includes/updater.php';  // actualizaciones desde GitHub
require_once LLMSTXT_PLUGIN_DIR . 'includes/privacy.php';  // CSV, borrado de leads y herramientas de privacidad

function llmstxt_log( $message ) {
    error_log( '[LLMs.txt Generator] ' . $message );
}

function llmstxt_table() {
    global $wpdb;
    return $wpdb->prefix . 'llmstxt_leads';
}

// ═════════════════════════════════════════════════════════════════════════════
// Base de datos: instalación y migraciones
// ═════════════════════════════════════════════════════════════════════════════

/**
 * Única fuente de verdad del esquema: columna => definición SQL.
 * Se usa para crear la tabla, para añadir columnas faltantes y en el diagnóstico.
 */
function llmstxt_schema_columns() {
    return [
        'email'          => "VARCHAR(255) NOT NULL DEFAULT ''",
        'name'           => 'VARCHAR(255) NULL',
        'website'        => 'VARCHAR(500) NULL',
        'nap'            => 'TEXT NULL',
        'urls_submitted' => 'TEXT NULL',
        'ip_address'     => 'VARCHAR(45) NULL',
        'status'         => "VARCHAR(20) NOT NULL DEFAULT 'pending'",
        'error_message'  => 'TEXT NULL',
        'attempts'       => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'created_at'     => 'DATETIME NULL',
        'updated_at'     => 'DATETIME NULL',
        'llms_content'   => 'LONGTEXT NULL',  // último llms.txt generado (descargable desde el admin)
    ];
}

function llmstxt_table_exists() {
    global $wpdb;
    $table = llmstxt_table();
    return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
}

function llmstxt_missing_columns() {
    global $wpdb;
    if ( ! llmstxt_table_exists() ) {
        return array_keys( llmstxt_schema_columns() );
    }
    $existing = $wpdb->get_col( 'DESCRIBE ' . llmstxt_table(), 0 ) ?: [];
    return array_values( array_diff( array_keys( llmstxt_schema_columns() ), $existing ) );
}

/**
 * Crea la tabla si no existe y añade cualquier columna que falte.
 * No usa dbDelta: en esta instalación demostró no añadir columnas a tablas existentes.
 * Devuelve la lista de errores (vacía si todo fue bien).
 */
function llmstxt_install_db() {
    global $wpdb;
    $table   = llmstxt_table();
    $errors  = [];
    $columns = llmstxt_schema_columns();

    if ( ! llmstxt_table_exists() ) {
        $defs = [ 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT' ];
        foreach ( $columns as $col => $def ) {
            $defs[] = "`$col` $def";
        }
        $defs[] = 'PRIMARY KEY (id)';
        $sql    = "CREATE TABLE IF NOT EXISTS `$table` (\n" . implode( ",\n", $defs ) . "\n) " . $wpdb->get_charset_collate();
        if ( false === $wpdb->query( $sql ) ) {
            $errors[] = 'CREATE TABLE: ' . $wpdb->last_error;
        }
    }

    foreach ( llmstxt_missing_columns() as $col ) {
        if ( ! llmstxt_table_exists() ) break;
        $r = $wpdb->query( "ALTER TABLE `$table` ADD COLUMN `$col` {$columns[ $col ]}" );
        if ( false === $r ) {
            $errors[] = "ALTER TABLE $col: " . $wpdb->last_error;
        }
    }

    foreach ( $errors as $e ) {
        llmstxt_log( $e );
    }

    if ( empty( $errors ) ) {
        update_option( 'llmstxt_db_version', LLMSTXT_VERSION );
        delete_transient( 'llmstxt_last_db_error' );
    } else {
        set_transient( 'llmstxt_last_db_error', implode( ' | ', $errors ), DAY_IN_SECONDS );
    }
    return $errors;
}

register_activation_hook( __FILE__, 'llmstxt_install_db' );

// Migra automáticamente cuando cambia la versión (también si el hook de activación no corrió).
add_action( 'plugins_loaded', function() {
    if ( get_option( 'llmstxt_db_version' ) !== LLMSTXT_VERSION ) {
        llmstxt_install_db();
    }
} );

// ═════════════════════════════════════════════════════════════════════════════
// Ajustes
// ═════════════════════════════════════════════════════════════════════════════

function llmstxt_get_model() {
    $model = trim( (string) get_option( 'llmstxt_model', LLMSTXT_DEFAULT_MODEL ) );
    return apply_filters( 'llmstxt_openai_model', '' !== $model ? $model : LLMSTXT_DEFAULT_MODEL );
}

add_action( 'admin_menu', function() {
    add_options_page( 'LLMs.txt Generator', 'LLMs.txt Generator', 'manage_options', 'llmstxt-settings', 'llmstxt_settings_page' );
    add_menu_page( 'LLMs.txt Leads', 'LLMs.txt Leads', 'manage_options', 'llmstxt-leads', 'llmstxt_leads_page', 'dashicons-email-alt', 30 );
} );

add_action( 'admin_init', function() {
    register_setting( 'llmstxt_options', 'llmstxt_openai_key',   [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'llmstxt_options', 'llmstxt_model',        [ 'sanitize_callback' => function( $v ) {
        $v = sanitize_text_field( $v );
        return '' !== $v ? $v : LLMSTXT_DEFAULT_MODEL;
    } ] );
    register_setting( 'llmstxt_options', 'llmstxt_notify_email', [ 'sanitize_callback' => 'sanitize_email' ] );
    register_setting( 'llmstxt_options', 'llmstxt_from_name',    [ 'sanitize_callback' => 'sanitize_text_field' ] );
    register_setting( 'llmstxt_options', 'llmstxt_from_email',   [ 'sanitize_callback' => 'sanitize_email' ] );
    register_setting( 'llmstxt_options', 'llmstxt_cta_url',      [ 'sanitize_callback' => 'esc_url_raw' ] );
} );

// Si el campo de la API key se deja vacío al guardar, conservar la existente.
add_filter( 'pre_update_option_llmstxt_openai_key', function( $new, $old ) {
    return ( '' === trim( (string) $new ) ) ? $old : $new;
}, 10, 2 );

// ═════════════════════════════════════════════════════════════════════════════
// Acciones de administración (admin-post.php, con nonce)
// ═════════════════════════════════════════════════════════════════════════════

function llmstxt_action_url( $action, $args = [] ) {
    $url = add_query_arg( array_merge( [ 'action' => $action ], $args ), admin_url( 'admin-post.php' ) );
    return wp_nonce_url( $url, $action );
}

function llmstxt_admin_redirect( $page, $type, $message ) {
    set_transient( 'llmstxt_notice_' . get_current_user_id(), [ 'type' => $type, 'message' => $message ], 60 );
    wp_safe_redirect( admin_url( 'llmstxt-leads' === $page ? 'admin.php?page=llmstxt-leads' : 'options-general.php?page=llmstxt-settings' ) );
    exit;
}

function llmstxt_check_admin_action( $action ) {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.', 403 );
    check_admin_referer( $action );
}

add_action( 'admin_notices', function() {
    $key    = 'llmstxt_notice_' . get_current_user_id();
    $notice = get_transient( $key );
    if ( ! $notice ) return;
    delete_transient( $key );
    printf(
        '<div class="notice notice-%s is-dismissible"><p><strong>LLMs.txt Generator:</strong> %s</p></div>',
        esc_attr( $notice['type'] ),
        esc_html( $notice['message'] )
    );
} );

add_action( 'admin_post_llmstxt_repair_db', function() {
    llmstxt_check_admin_action( 'llmstxt_repair_db' );
    $errors = llmstxt_install_db();
    if ( $errors ) {
        llmstxt_admin_redirect( 'settings', 'error', 'No se pudo reparar la base de datos: ' . implode( ' | ', $errors ) );
    }
    llmstxt_admin_redirect( 'settings', 'success', 'Base de datos verificada: la tabla y todas las columnas están en orden.' );
} );

add_action( 'admin_post_llmstxt_reset_ratelimit', function() {
    llmstxt_check_admin_action( 'llmstxt_reset_ratelimit' );
    // Cambiar la "generación" invalida todos los contadores (funciona también con caché de objetos).
    update_option( 'llmstxt_rl_gen', (int) get_option( 'llmstxt_rl_gen', 1 ) + 1, false );
    llmstxt_admin_redirect( 'settings', 'success', 'Límite de intentos reiniciado para todas las IPs.' );
} );

add_action( 'admin_post_llmstxt_test_openai', function() {
    llmstxt_check_admin_action( 'llmstxt_test_openai' );
    $result = llmstxt_test_openai();
    $ok     = ! is_wp_error( $result );
    $msg    = $ok ? 'Conexión correcta con OpenAI. Modelo "' . llmstxt_get_model() . '" disponible.' : $result->get_error_message();
    set_transient( 'llmstxt_last_api_test', [ 'ok' => $ok, 'message' => $msg, 'time' => current_time( 'mysql' ) ], WEEK_IN_SECONDS );
    llmstxt_admin_redirect( 'settings', $ok ? 'success' : 'error', $msg );
} );

add_action( 'admin_post_llmstxt_download', function() {
    llmstxt_check_admin_action( 'llmstxt_download' );
    global $wpdb;
    $lead_id = isset( $_GET['lead_id'] ) ? absint( $_GET['lead_id'] ) : 0;
    $content = $wpdb->get_var( $wpdb->prepare( 'SELECT llms_content FROM ' . llmstxt_table() . ' WHERE id = %d', $lead_id ) );
    if ( ! $content ) {
        llmstxt_admin_redirect( 'llmstxt-leads', 'error', "El lead #$lead_id no tiene un llms.txt guardado. Pulsa \"Reprocesar\" para generarlo." );
    }
    nocache_headers();
    header( 'Content-Type: text/plain; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="llms.txt"' );
    echo $content; // texto plano descargado como archivo, no se renderiza como HTML
    exit;
} );

add_action( 'admin_post_llmstxt_test_email', function() {
    llmstxt_check_admin_action( 'llmstxt_test_email' );
    $to     = wp_get_current_user()->user_email;
    $result = llmstxt_send_to_user( $to, 'Administrador', home_url( '/' ), "# Prueba\n\n> Email de prueba del plugin LLMs.txt Generator.\n" );
    if ( is_wp_error( $result ) ) {
        llmstxt_admin_redirect( 'settings', 'error', 'El email de prueba falló: ' . $result->get_error_message() );
    }
    $last = get_transient( 'llmstxt_last_mail' );
    $via  = $last ? ( 'smtp' === $last['mailer'] ? "SMTP {$last['host']}:{$last['port']}" : $last['mailer'] ) : 'desconocido';
    llmstxt_admin_redirect( 'settings', 'warning', "Email de prueba enviado a $to vía $via. Si en 5 minutos no está en esa bandeja ni en spam, mira la fila \"Email: último envío\" del Diagnóstico." );
} );

add_action( 'admin_post_llmstxt_reprocess', function() {
    llmstxt_check_admin_action( 'llmstxt_reprocess' );
    $lead_id = isset( $_GET['lead_id'] ) ? absint( $_GET['lead_id'] ) : 0;
    if ( ! $lead_id ) llmstxt_admin_redirect( 'llmstxt-leads', 'error', 'Lead no válido.' );

    // Se procesa en esta misma petición (no depende de WP-Cron).
    $result = llmstxt_run_job( $lead_id, true );
    if ( is_wp_error( $result ) ) {
        llmstxt_admin_redirect( 'llmstxt-leads', 'error', "Lead #$lead_id: " . $result->get_error_message() );
    }
    llmstxt_admin_redirect( 'llmstxt-leads', 'success', "Lead #$lead_id procesado. WordPress aceptó el email; si no llega, revisa spam y Diagnóstico → Email. El archivo se puede descargar con \"Descargar llms.txt\"." );
} );

// ═════════════════════════════════════════════════════════════════════════════
// Página de ajustes + diagnóstico
// ═════════════════════════════════════════════════════════════════════════════

/** Devuelve [total, atrasados] de trabajos del plugin en la cola de WP-Cron. */
function llmstxt_cron_queue_stats() {
    $total = 0;
    $late  = 0;
    if ( ! function_exists( '_get_cron_array' ) ) return [ 0, 0 ];
    foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
        if ( isset( $hooks[ LLMSTXT_CRON_HOOK ] ) ) {
            $n      = count( $hooks[ LLMSTXT_CRON_HOOK ] );
            $total += $n;
            if ( $timestamp < time() - 5 * MINUTE_IN_SECONDS ) $late += $n;
        }
    }
    return [ $total, $late ];
}

function llmstxt_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.' );
    global $wpdb;

    $has_key       = ! empty( get_option( 'llmstxt_openai_key' ) );
    $table_exists  = llmstxt_table_exists();
    $missing_cols  = llmstxt_missing_columns();
    $db_version    = get_option( 'llmstxt_db_version', '(no guardada)' );
    $last_db_error = get_transient( 'llmstxt_last_db_error' );
    $last_api_test = get_transient( 'llmstxt_last_api_test' );
    $cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    list( $queued, $late ) = llmstxt_cron_queue_stats();
    $error_leads   = $table_exists && ! in_array( 'status', $missing_cols, true )
        ? (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . llmstxt_table() . " WHERE status = 'error'" )
        : 0;

    $ok   = '<span style="color:#16a34a;font-weight:bold">✓</span> ';
    $fail = '<span style="color:#dc2626;font-weight:bold">✗</span> ';
    $warn = '<span style="color:#d97706;font-weight:bold">⚠</span> ';
    ?>
    <div class="wrap">
        <h1>LLMs.txt Generator — Configuración</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'llmstxt_options' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="llmstxt_openai_key">API Key de OpenAI</label></th>
                    <td>
                        <input type="password" id="llmstxt_openai_key" name="llmstxt_openai_key" value=""
                               placeholder="<?php echo $has_key ? esc_attr( '(configurada — pega una nueva para cambiarla)' ) : 'sk-proj-...'; ?>"
                               class="regular-text" autocomplete="new-password" />
                        <p class="description">
                            Obtenla en <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com/api-keys</a>.
                            <?php if ( $has_key ) echo '<br><span style="color:#16a34a">✓ Key configurada.</span>'; ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="llmstxt_model">Modelo de OpenAI</label></th>
                    <td>
                        <input type="text" id="llmstxt_model" name="llmstxt_model"
                               value="<?php echo esc_attr( llmstxt_get_model() ); ?>" class="regular-text" />
                        <p class="description">Por defecto <code><?php echo esc_html( LLMSTXT_DEFAULT_MODEL ); ?></code> (con búsqueda web). Usa "Probar conexión" abajo después de cambiarlo.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="llmstxt_notify_email">Email de notificación (tuyo)</label></th>
                    <td>
                        <input type="email" id="llmstxt_notify_email" name="llmstxt_notify_email"
                               value="<?php echo esc_attr( get_option( 'llmstxt_notify_email' ) ); ?>" class="regular-text" />
                        <p class="description">Recibirás un aviso por cada nuevo lead.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="llmstxt_from_name">Nombre del remitente</label></th>
                    <td>
                        <input type="text" id="llmstxt_from_name" name="llmstxt_from_name"
                               value="<?php echo esc_attr( get_option( 'llmstxt_from_name', 'Tu Agencia SEO' ) ); ?>" class="regular-text" />
                    </td>
                </tr>
                <tr>
                    <th><label for="llmstxt_from_email">Email del remitente</label></th>
                    <td>
                        <input type="email" id="llmstxt_from_email" name="llmstxt_from_email"
                               value="<?php echo esc_attr( get_option( 'llmstxt_from_email' ) ); ?>" class="regular-text" />
                        <p class="description">Usa un email de tu propio dominio para que los correos no caigan en spam. Si lo dejas vacío se usa el email del administrador.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="llmstxt_cta_url">Enlace del botón "Quiero saber más"</label></th>
                    <td>
                        <input type="text" id="llmstxt_cta_url" name="llmstxt_cta_url"
                               value="<?php echo esc_attr( get_option( 'llmstxt_cta_url', '/contacto' ) ); ?>" class="regular-text" />
                    </td>
                </tr>
                <?php do_action( 'llmstxt_settings_fields' ); ?>
            </table>
            <?php submit_button( 'Guardar configuración' ); ?>
        </form>

        <hr>
        <h2>Shortcode</h2>
        <code style="font-size:15px;padding:8px 12px;background:#f0f0f0;display:inline-block">[llmstxt_generator]</code>
        <p>El archivo llms.txt se genera en segundo plano (WP-Cron) y se envía al usuario por email en 1–3 minutos.</p>

        <hr>
        <h2>Diagnóstico</h2>
        <table class="widefat striped" style="max-width:820px">
            <tbody>
            <tr>
                <td style="width:260px">Versión del plugin / de la BD</td>
                <td><code><?php echo esc_html( LLMSTXT_VERSION ); ?></code> / <code><?php echo esc_html( $db_version ); ?></code>
                    <?php echo LLMSTXT_VERSION === $db_version ? $ok : $warn . 'La migración no terminó: pulsa "Reparar base de datos".'; ?></td>
            </tr>
            <tr>
                <td>Tabla <code><?php echo esc_html( llmstxt_table() ); ?></code></td>
                <td><?php echo $table_exists ? $ok . 'Existe' : $fail . '<strong>No existe</strong>'; ?></td>
            </tr>
            <tr>
                <td>Columnas</td>
                <td><?php echo empty( $missing_cols )
                    ? $ok . 'Todas presentes'
                    : $fail . 'Faltan: <code>' . esc_html( implode( ', ', $missing_cols ) ) . '</code>'; ?></td>
            </tr>
            <tr>
                <td>API Key OpenAI</td>
                <td><?php echo $has_key ? $ok . 'Configurada' : $fail . '<strong>No configurada</strong>'; ?></td>
            </tr>
            <tr>
                <td>Modelo</td>
                <td><code><?php echo esc_html( llmstxt_get_model() ); ?></code></td>
            </tr>
            <tr>
                <td>Última prueba de conexión</td>
                <td><?php
                    if ( ! $last_api_test ) {
                        echo $warn . 'Aún no se ha probado.';
                    } else {
                        echo ( $last_api_test['ok'] ? $ok : $fail ) . esc_html( $last_api_test['message'] ) . ' <small>(' . esc_html( $last_api_test['time'] ) . ')</small>';
                    } ?></td>
            </tr>
            <tr>
                <td>WP-Cron</td>
                <td><?php
                    if ( $cron_disabled ) {
                        echo $warn . '<code>DISABLE_WP_CRON</code> está activo. Los trabajos solo se ejecutan si tu hosting llama a <code>' . esc_html( site_url( 'wp-cron.php' ) ) . '</code> con un cron del sistema (cada 1–5 minutos).';
                    } else {
                        echo $ok . 'Activo';
                    } ?></td>
            </tr>
            <tr>
                <td>Trabajos en cola</td>
                <td><?php
                    echo esc_html( $queued ) . ' en cola';
                    if ( $late > 0 ) {
                        echo '<br>' . $fail . esc_html( $late ) . ' atrasados más de 5 minutos: WP-Cron no se está ejecutando. Revisa <a href="' . esc_url( admin_url( 'site-health.php' ) ) . '">Salud del sitio</a> (prueba de "loopback") o usa "Reprocesar" en LLMs.txt Leads.';
                    } ?></td>
            </tr>
            <tr>
                <td>Leads con error</td>
                <td><?php echo $error_leads
                    ? $warn . esc_html( $error_leads ) . ' — ver detalle en <a href="' . esc_url( admin_url( 'admin.php?page=llmstxt-leads' ) ) . '">LLMs.txt Leads</a>'
                    : $ok . 'Ninguno'; ?></td>
            </tr>
            <?php $mail = llmstxt_mail_diagnostics(); ?>
            <tr>
                <td>Email: remitente</td>
                <td><code><?php echo esc_html( $mail['from'] ); ?></code>
                    <?php foreach ( $mail['from_warnings'] as $w ) echo '<br>' . $warn . esc_html( $w ); ?>
                    <?php if ( empty( $mail['from_warnings'] ) ) echo $ok; ?></td>
            </tr>
            <tr>
                <td>Email: plugins que intervienen</td>
                <td><?php
                    if ( $mail['override'] ) echo 'wp_mail() reemplazado por <code>' . esc_html( $mail['override'] ) . '</code><br>';
                    echo $mail['sources']
                        ? 'Modifican el envío: <code>' . implode( '</code>, <code>', array_map( 'esc_html', $mail['sources'] ) ) . '</code><br><small>No todos son plugins SMTP. Lo que cuenta es el "Último envío" de abajo.</small>'
                        : ( $mail['override'] ? '' : 'Ninguno.' ); ?></td>
            </tr>
            <tr>
                <td>Email: último envío</td>
                <td><?php
                    $last = $mail['last'];
                    if ( ! $last ) {
                        echo $warn . 'Aún no hay datos. Pulsa "Enviarme un email de prueba".';
                    } else {
                        $is_smtp = 'smtp' === $last['mailer'];
                        $via     = $is_smtp
                            ? 'SMTP <code>' . esc_html( $last['host'] . ':' . $last['port'] ) . '</code>' . ( $last['auth'] ? ' (con autenticación)' : ' <strong>(sin autenticación)</strong>' )
                            : '<code>' . esc_html( $last['mailer'] ) . '</code>';
                        echo ( $is_smtp && $last['auth'] ? $ok : $warn )
                            . 'Para <code>' . esc_html( $last['to'] ) . '</code> vía ' . $via
                            . ' — WordPress: ' . esc_html( $last['result'] ) . ' <small>(' . esc_html( $last['time'] ) . ')</small>';
                        if ( 'mail' === $last['mailer'] ) {
                            echo '<br>Se envió con la función <code>mail()</code> del servidor, sin autenticación: Gmail/Outlook suelen descartarlo. Configura <strong>WP Mail SMTP</strong> (o FluentSMTP) con tu correo del dominio, Brevo, SendGrid o Mailgun.';
                        } elseif ( 'sendmail' === $last['mailer'] || 'qmail' === $last['mailer'] ) {
                            echo '<br>Se envió con ' . esc_html( $last['mailer'] ) . ' del servidor, sin autenticación. Configura un plugin SMTP.';
                        } elseif ( $is_smtp ) {
                            echo '<br>El servidor SMTP aceptó el correo. Si no llega, revisa spam, el registro de envíos de tu plugin SMTP y los registros SPF/DKIM de tu dominio.';
                        }
                    } ?></td>
            </tr>
            <?php if ( $last_db_error ) : ?>
            <tr>
                <td><strong>Último error de BD</strong></td>
                <td style="color:#dc2626"><code><?php echo esc_html( $last_db_error ); ?></code></td>
            </tr>
            <?php endif; ?>
            <?php do_action( 'llmstxt_diagnostics_rows', $ok, $fail, $warn ); ?>
            </tbody>
        </table>
        <p>
            <a href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_repair_db' ) ); ?>" class="button button-primary">Reparar base de datos</a>
            <a href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_test_openai' ) ); ?>" class="button">Probar conexión con OpenAI</a>
            <a href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_test_email' ) ); ?>" class="button">Enviarme un email de prueba</a>
            <a href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_reset_ratelimit' ) ); ?>" class="button">Limpiar límite de intentos</a>
            <?php do_action( 'llmstxt_diagnostics_buttons' ); ?>
        </p>
        <p class="description">Los administradores conectados no tienen límite de intentos. Los errores detallados también se escriben en <code>wp-content/debug.log</code> si activas <code>WP_DEBUG_LOG</code>.</p>
    </div>
    <?php
}

// ═════════════════════════════════════════════════════════════════════════════
// Página de leads
// ═════════════════════════════════════════════════════════════════════════════

function llmstxt_leads_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No autorizado.' );
    global $wpdb;

    if ( ! empty( llmstxt_missing_columns() ) ) {
        echo '<div class="wrap"><h1>Leads capturados</h1><div class="notice notice-error"><p>La tabla de leads está incompleta. Ve a <a href="'
            . esc_url( admin_url( 'options-general.php?page=llmstxt-settings' ) ) . '">Ajustes → LLMs.txt Generator</a> y pulsa "Reparar base de datos".</p></div></div>';
        return;
    }

    $total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . llmstxt_table() );
    $leads  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . llmstxt_table() . ' ORDER BY id DESC LIMIT %d', 500 ) );
    $labels = [
        'pending'    => '<span style="color:#d97706">⏳ Pendiente</span>',
        'processing' => '<span style="color:#2563eb">⚙ Procesando</span>',
        'done'       => '<span style="color:#16a34a">✓ Enviado</span>',
        'error'      => '<span style="color:#dc2626">✗ Error</span>',
    ];
    ?>
    <div class="wrap">
        <h1>Leads capturados (<?php echo intval( $total ); ?>)</h1>
        <?php if ( $total > count( $leads ) ) : ?><p>Se muestran los <?php echo intval( count( $leads ) ); ?> más recientes. Exporta a CSV para verlos todos.</p><?php endif; ?>
        <?php do_action( 'llmstxt_leads_toolbar' ); ?>
        <p class="description">"Reprocesar" genera el archivo y envía el email en ese momento, sin esperar a WP-Cron. Puede tardar 1–3 minutos: no cierres la página.</p>
        <table class="widefat striped">
            <thead><tr>
                <th>#</th><th>Email</th><th>Nombre</th><th>Sitio web</th><th>Estado</th><th>Detalle</th><th>Intentos</th><th>IP</th><th>Fecha</th><th></th>
            </tr></thead>
            <tbody>
            <?php if ( $leads ) : foreach ( $leads as $lead ) : ?>
                <tr>
                    <td><?php echo intval( $lead->id ); ?></td>
                    <td><?php echo esc_html( $lead->email ); ?></td>
                    <td><?php echo esc_html( $lead->name ); ?></td>
                    <td><a href="<?php echo esc_url( $lead->website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $lead->website ); ?></a></td>
                    <td><?php echo $labels[ $lead->status ] ?? esc_html( $lead->status ); ?></td>
                    <td style="max-width:320px;word-break:break-word"><?php echo $lead->error_message ? '<small style="color:#dc2626">' . esc_html( $lead->error_message ) . '</small>' : '—'; ?></td>
                    <td><?php echo intval( $lead->attempts ); ?></td>
                    <td><?php echo esc_html( $lead->ip_address ?: '—' ); ?></td>
                    <td><?php echo esc_html( $lead->created_at ); ?></td>
                    <td style="white-space:nowrap">
                        <?php if ( ! empty( $lead->llms_content ) ) : ?>
                            <a class="button button-small" href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_download', [ 'lead_id' => $lead->id ] ) ); ?>">Descargar llms.txt</a>
                        <?php endif; ?>
                        <?php if ( empty( $lead->urls_submitted ) ) : ?>
                            <small>Sin URLs guardadas</small>
                        <?php else : ?>
                            <a class="button button-small"
                               href="<?php echo esc_url( llmstxt_action_url( 'llmstxt_reprocess', [ 'lead_id' => $lead->id ] ) ); ?>"
                               onclick="return confirm('¿Generar y enviar de nuevo el llms.txt a <?php echo esc_js( $lead->email ); ?>?');">Reprocesar</a>
                        <?php endif; ?>
                        <?php do_action( 'llmstxt_lead_row_actions', $lead ); ?>
                    </td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="10">Aún no hay leads.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ═════════════════════════════════════════════════════════════════════════════
// Shortcode (formulario público)
// ═════════════════════════════════════════════════════════════════════════════

add_shortcode( 'llmstxt_generator', function() {
    wp_enqueue_style(  'llmstxt-style',  LLMSTXT_PLUGIN_URL . 'assets/style.css', [], LLMSTXT_VERSION );
    wp_enqueue_script( 'llmstxt-script', LLMSTXT_PLUGIN_URL . 'assets/app.js', [ 'jquery' ], LLMSTXT_VERSION, true );
    wp_localize_script( 'llmstxt-script', 'llmstxtAjax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'llmstxt_nonce' ),
    ] );
    $cta_url = get_option( 'llmstxt_cta_url', '/contacto' ) ?: '/contacto';

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
                        <span class="llmstxt-hint">Una por línea. Máximo <?php echo intval( LLMSTXT_MAX_URLS ); ?> URLs.</span>
                    </label>
                    <textarea id="llmstxt-urls" rows="6"
                        placeholder="https://tuempresa.com&#10;https://tuempresa.com/servicios&#10;https://tuempresa.com/contacto" required></textarea>
                </div>
                <div id="llmstxt-lead-error" class="llmstxt-error" style="display:none"></div>
                <button type="submit" id="llmstxt-submit-btn" class="llmstxt-btn">Generar mi llms.txt gratis →</button>
                <p class="llmstxt-privacy">Sin spam. Te enviaremos el archivo a tu email en 1-3 minutos.</p>
            </form>
        </div>

        <div id="llmstxt-step-success" class="llmstxt-card" style="display:none">
            <div class="llmstxt-success-header">
                <span class="llmstxt-check">✓</span>
                <h3>¡Solicitud recibida!</h3>
                <p id="llmstxt-success-msg">Estamos analizando tu sitio. Recibirás el archivo en tu email en los próximos 1-3 minutos.</p>
            </div>
            <div class="llmstxt-cta-box">
                <p><strong>¿Quieres que tu negocio aparezca en más búsquedas de IA?</strong></p>
                <p>Nuestros servicios de SEO Local y GEO ayudan a negocios como el tuyo a dominar los resultados en Google y en herramientas de IA.</p>
                <a href="<?php echo esc_url( $cta_url ); ?>" class="llmstxt-btn llmstxt-btn-cta">Quiero saber más →</a>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
} );

// ═════════════════════════════════════════════════════════════════════════════
// AJAX: validar, guardar lead y encolar el trabajo
// ═════════════════════════════════════════════════════════════════════════════

add_action( 'wp_ajax_llmstxt_generate',        'llmstxt_ajax_generate' );
add_action( 'wp_ajax_nopriv_llmstxt_generate', 'llmstxt_ajax_generate' );

function llmstxt_ajax_generate() {

    // Un nonce caducado (p. ej. por caché de página) no debe verse como "error de conexión".
    if ( ! check_ajax_referer( 'llmstxt_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => 'La página lleva demasiado tiempo abierta. Recárgala e inténtalo de nuevo.', 'code' => 'nonce' ], 403 );
    }

    $ip = llmstxt_get_ip();
    if ( llmstxt_rate_limited( $ip ) ) {
        wp_send_json_error( [ 'message' => 'Has alcanzado el límite de generaciones por hora. Intenta más tarde.', 'code' => 'rate_limit' ] );
    }

    $name    = sanitize_text_field(     wp_unslash( $_POST['name']    ?? '' ) );
    $email   = sanitize_email(          wp_unslash( $_POST['email']   ?? '' ) );
    $website = esc_url_raw( trim(       wp_unslash( $_POST['website'] ?? '' ) ) );
    $nap     = sanitize_textarea_field( wp_unslash( $_POST['nap']     ?? '' ) );
    $urls    = sanitize_textarea_field( wp_unslash( $_POST['urls']    ?? '' ) );

    if ( ! $name || ! $email || ! $website || ! $nap || ! $urls ) {
        wp_send_json_error( [ 'message' => 'Por favor completa todos los campos requeridos.' ] );
    }
    if ( mb_strlen( $name ) > 100 ) {
        wp_send_json_error( [ 'message' => 'El nombre es demasiado largo (máx. 100 caracteres).' ] );
    }
    if ( ! is_email( $email ) ) {
        wp_send_json_error( [ 'message' => 'El email no es válido.' ] );
    }
    if ( ! llmstxt_is_http_url( $website ) ) {
        wp_send_json_error( [ 'message' => 'La URL del sitio debe comenzar con https:// o http://.' ] );
    }
    if ( mb_strlen( $nap ) > LLMSTXT_MAX_FIELD ) {
        wp_send_json_error( [ 'message' => 'El campo NAP es demasiado largo (máx. ' . LLMSTXT_MAX_FIELD . ' caracteres).' ] );
    }

    $urls_list = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/\R/', $urls ) ) ) ) );
    if ( count( $urls_list ) > LLMSTXT_MAX_URLS ) {
        wp_send_json_error( [ 'message' => 'Máximo ' . LLMSTXT_MAX_URLS . ' URLs permitidas.' ] );
    }
    foreach ( $urls_list as $u ) {
        if ( ! llmstxt_is_http_url( $u ) ) {
            wp_send_json_error( [ 'message' => 'URL no válida (debe empezar con https://): ' . $u ] );
        }
    }
    $urls = implode( "\n", $urls_list );

    $lead_id = llmstxt_insert_lead( [
        'email'          => $email,
        'name'           => $name,
        'website'        => $website,
        'nap'            => $nap,
        'urls_submitted' => $urls,
        'ip_address'     => $ip,
    ] );
    if ( ! $lead_id ) {
        wp_send_json_error( [ 'message' => 'Error interno al guardar la solicitud. Por favor intenta de nuevo.' ] );
    }

    // Solo los envíos guardados cuentan para el límite.
    llmstxt_rate_hit( $ip );

    llmstxt_notify_agency( $email, $name, $website );

    wp_schedule_single_event( time(), LLMSTXT_CRON_HOOK, [ (int) $lead_id ] );
    spawn_cron(); // dispara WP-Cron en segundo plano (mecanismo oficial de WordPress)

    wp_send_json_success( [ 'email' => $email ] );
}

/** Inserta el lead; si falla por una columna faltante, repara la tabla y reintenta una vez. */
function llmstxt_insert_lead( array $data ) {
    global $wpdb;
    $now  = current_time( 'mysql' );
    $data = array_merge( $data, [ 'status' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now ] );

    for ( $try = 1; $try <= 2; $try++ ) {
        if ( false !== $wpdb->insert( llmstxt_table(), $data ) ) {
            return (int) $wpdb->insert_id;
        }
        $err = $wpdb->last_error ?: '(sin mensaje de error)';
        llmstxt_log( "Insert falló (intento $try): $err" );
        set_transient( 'llmstxt_last_db_error', $err, DAY_IN_SECONDS );
        if ( 1 === $try ) {
            llmstxt_install_db();
        }
    }
    return 0;
}

// ═════════════════════════════════════════════════════════════════════════════
// Procesamiento (WP-Cron o "Reprocesar" desde el admin)
// ═════════════════════════════════════════════════════════════════════════════

// Acepta hasta 6 argumentos por compatibilidad con trabajos encolados por versiones ≤ 3.1
// ([lead_id, email, name, website, nap, urls]). Los nuevos solo pasan [lead_id].
add_action( LLMSTXT_CRON_HOOK, function( $lead_id, ...$legacy ) {
    llmstxt_run_job( (int) $lead_id, false, $legacy[3] ?? '', $legacy[4] ?? '' );
}, 10, 6 );

/**
 * Genera el llms.txt de un lead y lo envía por email.
 * Marca el lead como processing → done / error y guarda el motivo del error.
 *
 * @return true|WP_Error
 */
function llmstxt_run_job( $lead_id, $force = false, $legacy_nap = '', $legacy_urls = '' ) {
    global $wpdb;
    $table = llmstxt_table();
    $lead  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $lead_id ) );

    if ( ! $lead ) {
        llmstxt_log( "Lead $lead_id no encontrado." );
        return new WP_Error( 'not_found', 'Lead no encontrado.' );
    }
    if ( 'done' === $lead->status && ! $force ) {
        return true; // ya enviado: evita duplicados si el evento se ejecuta dos veces
    }

    @set_time_limit( LLMSTXT_API_TIMEOUT + 60 );

    $wpdb->query( $wpdb->prepare(
        "UPDATE $table SET status = 'processing', attempts = attempts + 1, error_message = NULL, updated_at = %s WHERE id = %d",
        current_time( 'mysql' ), $lead_id
    ) );

    $nap  = $lead->nap ?: $legacy_nap;
    $urls = $lead->urls_submitted ?: $legacy_urls;

    $content = llmstxt_generate_content( $lead->website, $nap, $urls );
    if ( is_wp_error( $content ) ) {
        return llmstxt_fail_lead( $lead_id, $content );
    }

    // Guardar el archivo antes de enviar: así se puede descargar desde el admin aunque el email no llegue.
    $wpdb->update( $table, [ 'llms_content' => $content ], [ 'id' => $lead_id ] );

    $sent = llmstxt_send_to_user( $lead->email, $lead->name, $lead->website, $content );
    if ( is_wp_error( $sent ) ) {
        return llmstxt_fail_lead( $lead_id, $sent );
    }

    $wpdb->update(
        $table,
        [ 'status' => 'done', 'error_message' => null, 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => $lead_id ]
    );
    return true;
}

function llmstxt_fail_lead( $lead_id, WP_Error $error ) {
    global $wpdb;
    $msg = mb_substr( $error->get_error_message(), 0, 1000 );
    llmstxt_log( "Lead $lead_id: $msg" );
    $wpdb->update(
        llmstxt_table(),
        [ 'status' => 'error', 'error_message' => $msg, 'updated_at' => current_time( 'mysql' ) ],
        [ 'id' => $lead_id ]
    );
    return $error;
}

// ═════════════════════════════════════════════════════════════════════════════
// OpenAI
// ═════════════════════════════════════════════════════════════════════════════

function llmstxt_is_reasoning_model( $model ) {
    return (bool) preg_match( '/^(gpt-5|o\d)/i', $model );
}

/** Extrae un mensaje legible de una respuesta de error de OpenAI. */
function llmstxt_openai_error( $response ) {
    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    $msg  = $body['error']['message'] ?? mb_substr( wp_remote_retrieve_body( $response ), 0, 300 );
    $hint = '';
    if ( 401 === $code ) $hint = ' Revisa la API key en Ajustes.';
    if ( 404 === $code ) $hint = ' Revisa el nombre del modelo en Ajustes.';
    if ( 429 === $code ) $hint = ' Límite de uso o saldo insuficiente en tu cuenta de OpenAI.';
    return new WP_Error( 'openai_http_' . $code, "OpenAI respondió HTTP $code: $msg.$hint" );
}

/** Verifica la API key y que el modelo configurado exista. */
function llmstxt_test_openai() {
    $api_key = get_option( 'llmstxt_openai_key' );
    if ( ! $api_key ) return new WP_Error( 'no_key', 'No hay API key configurada.' );

    $response = wp_remote_get( 'https://api.openai.com/v1/models/' . rawurlencode( llmstxt_get_model() ), [
        'timeout' => 20,
        'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
    ] );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'http', 'No se pudo conectar con OpenAI desde tu servidor: ' . $response->get_error_message() );
    }
    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return llmstxt_openai_error( $response );
    }
    return true;
}

/** @return string|WP_Error Contenido del llms.txt. */
function llmstxt_generate_content( $website, $nap, $urls ) {
    $api_key = get_option( 'llmstxt_openai_key' );
    if ( ! $api_key ) {
        return new WP_Error( 'no_key', 'No hay API key de OpenAI configurada.' );
    }
    if ( ! $urls ) {
        return new WP_Error( 'no_urls', 'El lead no tiene URLs guardadas.' );
    }

    $model   = llmstxt_get_model();
    $payload = [
        'model' => $model,
        'tools' => [ [ 'type' => 'web_search' ] ],
        'input' => llmstxt_build_prompt( $website, $nap, $urls ),
    ];
    if ( llmstxt_is_reasoning_model( $model ) ) {
        // Esfuerzo bajo: suficiente para esta tarea y mucho más rápido (evita timeouts).
        $payload['reasoning'] = [ 'effort' => 'low' ];
    }
    $payload = apply_filters( 'llmstxt_openai_payload', $payload );

    $response = wp_remote_post( 'https://api.openai.com/v1/responses', [
        'timeout' => LLMSTXT_API_TIMEOUT,
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( $payload ),
    ] );

    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'http', 'No se pudo conectar con OpenAI: ' . $response->get_error_message() );
    }
    if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return llmstxt_openai_error( $response );
    }

    $body   = json_decode( wp_remote_retrieve_body( $response ), true );
    $status = $body['status'] ?? 'completed';
    $text   = llmstxt_extract_content( $body );

    if ( 'completed' !== $status && '' === $text ) {
        $reason = $body['incomplete_details']['reason'] ?? $status;
        return new WP_Error( 'incomplete', "OpenAI no terminó la respuesta (motivo: $reason)." );
    }
    if ( '' === $text ) {
        return new WP_Error( 'empty', 'OpenAI respondió sin contenido de texto.' );
    }
    return $text;
}

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
2. Detect the language of the business (Spanish or English) from the content found and write the file in that language.
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
- Output ONLY the llms.txt content — no explanations, no markdown fences, no preamble, no citations
PROMPT;
}

/** Une el texto de todos los mensajes de la Responses API y quita fences de markdown. */
function llmstxt_extract_content( $body ) {
    if ( empty( $body['output'] ) || ! is_array( $body['output'] ) ) return '';
    $parts = [];
    foreach ( $body['output'] as $item ) {
        if ( ( $item['type'] ?? '' ) !== 'message' || empty( $item['content'] ) ) continue;
        foreach ( $item['content'] as $block ) {
            if ( ( $block['type'] ?? '' ) === 'output_text' && ! empty( $block['text'] ) ) {
                $parts[] = $block['text'];
            }
        }
    }
    $text = trim( implode( "\n\n", $parts ) );
    $text = preg_replace( '/^```[a-z]*\s*\n|\n?```\s*$/i', '', $text );
    return trim( $text );
}

// ═════════════════════════════════════════════════════════════════════════════
// Utilidades
// ═════════════════════════════════════════════════════════════════════════════

function llmstxt_is_http_url( $url ) {
    $p = wp_parse_url( $url );
    return ! empty( $p['scheme'] ) && in_array( strtolower( $p['scheme'] ), [ 'http', 'https' ], true ) && ! empty( $p['host'] );
}

function llmstxt_rate_key( $ip ) {
    return 'llmstxt_rl_' . (int) get_option( 'llmstxt_rl_gen', 1 ) . '_' . md5( $ip );
}

function llmstxt_rate_limited( $ip ) {
    if ( current_user_can( 'manage_options' ) ) return false; // el admin puede probar sin límite
    return (int) get_transient( llmstxt_rate_key( $ip ) ) >= LLMSTXT_RATE_LIMIT;
}

function llmstxt_rate_hit( $ip ) {
    if ( current_user_can( 'manage_options' ) ) return;
    $key = llmstxt_rate_key( $ip );
    set_transient( $key, (int) get_transient( $key ) + 1, LLMSTXT_RATE_WINDOW );
}

function llmstxt_get_ip() {
    return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
}

// ═════════════════════════════════════════════════════════════════════════════
// Emails
// ═════════════════════════════════════════════════════════════════════════════

/** Datos para el diagnóstico de email: remitente efectivo, avisos y si hay SMTP configurado. */
function llmstxt_mail_diagnostics() {
    $from = get_option( 'llmstxt_from_email' );
    if ( ! is_email( $from ) ) $from = get_option( 'admin_email' );

    $from_domain = strtolower( substr( strrchr( $from, '@' ), 1 ) );
    $site_domain = strtolower( preg_replace( '/^www\./i', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
    $free        = [ 'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com', 'yahoo.com', 'icloud.com', 'aol.com', 'proton.me', 'protonmail.com' ];

    $warnings = [];
    if ( in_array( $from_domain, $free, true ) ) {
        $warnings[] = "El remitente es una cuenta @$from_domain. Gmail y los demás rechazan los correos que dicen venir de @$from_domain pero salen de otro servidor (DMARC). Usa un email de tu dominio, por ejemplo info@$site_domain.";
    } elseif ( $from_domain && $site_domain && $from_domain !== $site_domain && substr( $site_domain, -strlen( '.' . $from_domain ) ) !== '.' . $from_domain ) {
        $warnings[] = "El dominio del remitente ($from_domain) no coincide con el del sitio ($site_domain). Asegúrate de que tu SMTP esté autorizado para enviar desde $from_domain.";
    }

    // Qué plugins se enganchan a phpmailer_init (no todos son SMTP) y si wp_mail() está reemplazado.
    $sources = llmstxt_phpmailer_hook_sources();
    $override = '';
    try {
        $file = ( new ReflectionFunction( 'wp_mail' ) )->getFileName();
        if ( $file && false === strpos( wp_normalize_path( $file ), '/wp-includes/' ) ) {
            $override = llmstxt_source_from_path( $file );
        }
    } catch ( ReflectionException $e ) {}

    return [
        'from'          => $from,
        'from_warnings' => $warnings,
        'sources'       => $sources,
        'override'      => $override,
        'last'          => get_transient( 'llmstxt_last_mail' ),
    ];
}

/** Convierte la ruta de un archivo en "plugin: carpeta", "tema: …" u otro origen legible. */
function llmstxt_source_from_path( $path ) {
    $path = wp_normalize_path( (string) $path );
    if ( preg_match( '#/(mu-plugins|plugins)/([^/]+)#', $path, $m ) ) {
        return ( 'mu-plugins' === $m[1] ? 'mu-plugin: ' : 'plugin: ' ) . preg_replace( '/\.php$/', '', $m[2] );
    }
    if ( preg_match( '#/themes/([^/]+)#', $path, $m ) ) return 'tema: ' . $m[1];
    return basename( $path );
}

/** Lista los orígenes (plugins/temas) de los callbacks enganchados a phpmailer_init, sin incluir este plugin. */
function llmstxt_phpmailer_hook_sources() {
    global $wp_filter;
    $sources = [];
    if ( empty( $wp_filter['phpmailer_init'] ) ) return [];
    foreach ( $wp_filter['phpmailer_init']->callbacks as $callbacks ) {
        foreach ( $callbacks as $cb ) {
            $fn = $cb['function'];
            try {
                if ( is_array( $fn ) ) {
                    $ref = new ReflectionMethod( $fn[0], $fn[1] );
                } elseif ( is_string( $fn ) && strpos( $fn, '::' ) ) {
                    $ref = new ReflectionMethod( $fn );
                } else {
                    $ref = new ReflectionFunction( $fn );
                }
                $src = llmstxt_source_from_path( $ref->getFileName() );
            } catch ( ReflectionException $e ) {
                $src = 'desconocido';
            }
            if ( false === strpos( $src, 'llms-txt-generator' ) ) $sources[ $src ] = true;
        }
    }
    return array_keys( $sources );
}

function llmstxt_mail_headers( $content_type = true ) {
    $from_name  = get_option( 'llmstxt_from_name', 'Tu Agencia SEO' ) ?: get_bloginfo( 'name' );
    $from_email = get_option( 'llmstxt_from_email' );
    if ( ! is_email( $from_email ) ) $from_email = get_option( 'admin_email' );
    $from_name  = str_replace( [ "\r", "\n", '"' ], '', $from_name );

    $headers = [ "From: \"{$from_name}\" <{$from_email}>" ];
    if ( $content_type ) $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    return $headers;
}

/** Envía el archivo adjunto como "llms.txt". @return true|WP_Error */
function llmstxt_send_to_user( $email, $name, $website, $content ) {
    $dir = trailingslashit( get_temp_dir() ) . 'llmstxt-' . wp_generate_password( 12, false, false );
    if ( ! wp_mkdir_p( $dir ) ) {
        return new WP_Error( 'tmp', 'No se pudo crear la carpeta temporal para el adjunto.' );
    }
    $file = $dir . '/llms.txt';
    if ( false === file_put_contents( $file, $content ) ) {
        @rmdir( $dir );
        return new WP_Error( 'tmp', 'No se pudo escribir el archivo temporal llms.txt.' );
    }

    $host      = wp_parse_url( $website, PHP_URL_HOST ) ?: 'tudominio.com';
    $scheme    = wp_parse_url( $website, PHP_URL_SCHEME ) ?: 'https';
    $from_name = get_option( 'llmstxt_from_name', 'Tu Agencia SEO' );
    $subject   = 'Tu archivo llms.txt está listo — ' . $host;
    $message   = "Hola {$name},\n\n"
               . "Tu archivo llms.txt para {$website} está listo. Lo encontrarás adjunto a este correo.\n\n"
               . "Súbelo a la raíz de tu dominio para que quede disponible en:\n{$scheme}://{$host}/llms.txt\n\n"
               . "— {$from_name}";

    $mail_error = null;
    $transport  = null;
    $catch      = function( $wp_error ) use ( &$mail_error ) { $mail_error = $wp_error; };
    // Se ejecuta al final, después de cualquier plugin SMTP: registra por dónde sale realmente el correo.
    $capture    = function( $phpmailer ) use ( &$transport, $email ) {
        if ( 'mail' === $phpmailer->Mailer && empty( $phpmailer->Sender ) ) {
            // Con mail() del servidor, alinear el Return-Path con el remitente mejora SPF/DMARC.
            $phpmailer->Sender = $phpmailer->From;
        }
        $transport = [
            'to'     => $email,
            'mailer' => (string) $phpmailer->Mailer,
            'host'   => (string) $phpmailer->Host,
            'port'   => (int) $phpmailer->Port,
            'auth'   => (bool) $phpmailer->SMTPAuth,
            'from'   => (string) $phpmailer->From,
            'sender' => (string) $phpmailer->Sender,
            'time'   => current_time( 'mysql' ),
        ];
    };
    add_action( 'wp_mail_failed', $catch );
    add_action( 'phpmailer_init', $capture, PHP_INT_MAX );
    $sent = wp_mail( $email, $subject, $message, llmstxt_mail_headers(), [ $file ] );
    remove_action( 'phpmailer_init', $capture, PHP_INT_MAX );
    remove_action( 'wp_mail_failed', $catch );

    @unlink( $file );
    @rmdir( $dir );

    $transport = $transport ?: [ 'to' => $email, 'mailer' => '(desconocido: wp_mail() reemplazado por un plugin)', 'host' => '', 'port' => 0, 'auth' => false, 'from' => '', 'sender' => '', 'time' => current_time( 'mysql' ) ];
    $transport['result'] = $sent ? 'aceptado' : 'fallido';
    set_transient( 'llmstxt_last_mail', $transport, WEEK_IN_SECONDS );

    if ( ! $sent ) {
        $detail = $mail_error instanceof WP_Error ? $mail_error->get_error_message() : 'sin detalle';
        return new WP_Error( 'mail', "El llms.txt se generó pero el email no se pudo enviar ($detail). Instala/revisa WP Mail SMTP." );
    }
    return true;
}

function llmstxt_notify_agency( $user_email, $name, $website ) {
    $notify = get_option( 'llmstxt_notify_email' );
    if ( ! is_email( $notify ) ) return;
    $subject = "Nuevo lead: {$name} ({$website})";
    $message = "Alguien usó el generador de llms.txt.\n\nNombre: {$name}\nEmail: {$user_email}\nSitio: {$website}\n\nRevisa los leads en: " . admin_url( 'admin.php?page=llmstxt-leads' );
    wp_mail( $notify, $subject, $message, llmstxt_mail_headers() );
}
