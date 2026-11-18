<?php
/**
 * Actualizaciones automáticas desde GitHub.
 *
 * Cada vez que WordPress busca actualizaciones, se lee la versión del plugin en la rama configurada
 * del repositorio (llms-txt-generator/llms-txt-generator.php). Si es mayor que la instalada, WordPress
 * muestra "Hay una nueva versión" y descarga dist/llms-txt-generator.zip de esa misma rama.
 * Funciona con el botón "Actualizar ahora" y con las actualizaciones automáticas nativas de WordPress.
 * Para repositorios privados hace falta un token de GitHub de solo lectura.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'LLMSTXT_UPDATE_CACHE',     'llmstxt_update_info' );
define( 'LLMSTXT_REMOTE_MAIN_FILE', 'llms-txt-generator/llms-txt-generator.php' );
define( 'LLMSTXT_REMOTE_ZIP',       'dist/llms-txt-generator.zip' );
define( 'LLMSTXT_REMOTE_CHANGELOG', 'CHANGELOG.md' );

function llmstxt_update_repo() {
    $repo = trim( (string) get_option( 'llmstxt_update_repo', 'designsandit/llmstxt' ) );
    return preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ? $repo : '';
}

function llmstxt_update_branch() {
    $branch = trim( (string) get_option( 'llmstxt_update_branch', 'main' ) );
    return '' !== $branch ? $branch : 'main';
}

/** El token puede definirse en wp-config.php (LLMSTXT_GITHUB_TOKEN) o guardarse en Ajustes. */
function llmstxt_update_token() {
    if ( defined( 'LLMSTXT_GITHUB_TOKEN' ) && LLMSTXT_GITHUB_TOKEN ) return (string) LLMSTXT_GITHUB_TOKEN;
    return (string) get_option( 'llmstxt_github_token', '' );
}

function llmstxt_github_contents_url( $path ) {
    return 'https://api.github.com/repos/' . llmstxt_update_repo() . '/contents/' . $path . '?ref=' . rawurlencode( llmstxt_update_branch() );
}

function llmstxt_plugin_basename() {
    return plugin_basename( LLMSTXT_PLUGIN_FILE );
}

// Añade el token y el formato "raw" a las peticiones a la API de contenidos de este repositorio.
// Así funciona también la descarga del ZIP que hace el actualizador de WordPress.
add_filter( 'http_request_args', function( $args, $url ) {
    $repo = llmstxt_update_repo();
    if ( ! $repo || 0 !== strpos( $url, 'https://api.github.com/repos/' . $repo . '/contents/' ) ) {
        return $args;
    }
    $args['headers']                         = isset( $args['headers'] ) ? (array) $args['headers'] : [];
    $args['headers']['Accept']               = 'application/vnd.github.raw+json';
    $args['headers']['X-GitHub-Api-Version'] = '2022-11-28';
    $token = llmstxt_update_token();
    if ( $token ) {
        $args['headers']['Authorization'] = 'Bearer ' . $token;
    }
    return $args;
}, 10, 2 );

/** Descarga un archivo del repo en crudo. @return string|WP_Error */
function llmstxt_github_get( $path ) {
    if ( ! llmstxt_update_repo() ) {
        return new WP_Error( 'repo', 'El repositorio configurado no es válido (formato: usuario/repositorio).' );
    }
    $response = wp_remote_get( llmstxt_github_contents_url( $path ), [ 'timeout' => 15 ] );
    if ( is_wp_error( $response ) ) {
        return new WP_Error( 'http', 'No se pudo conectar con GitHub: ' . $response->get_error_message() );
    }
    $code = wp_remote_retrieve_response_code( $response );
    if ( 200 === $code ) {
        return wp_remote_retrieve_body( $response );
    }
    $has_token = '' !== llmstxt_update_token();
    switch ( $code ) {
        case 401:
            $msg = 'GitHub rechazó el token (401). Puede haber caducado o estar mal copiado.';
            break;
        case 403:
            $msg = 'GitHub denegó el acceso (403): el token no tiene permiso de lectura de contenidos o se superó el límite de peticiones.';
            break;
        case 404:
            $msg = $has_token
                ? 'GitHub respondió 404: el token no tiene acceso a ' . llmstxt_update_repo() . ', o no existe la rama "' . llmstxt_update_branch() . '" o el archivo ' . $path . '.'
                : 'GitHub respondió 404. Si el repositorio es privado, falta configurar el token de GitHub.';
            break;
        default:
            $msg = "GitHub respondió HTTP $code.";
    }
    return new WP_Error( 'github_' . $code, $msg );
}

/**
 * Versión publicada en GitHub (con caché de 6 h; los errores se guardan 1 h).
 * @return array{version:string,error:string,checked:int}
 */
function llmstxt_remote_info( $force = false ) {
    $cached = get_site_transient( LLMSTXT_UPDATE_CACHE );
    if ( ! $force && is_array( $cached ) ) return $cached;

    $info = [ 'version' => '', 'error' => '', 'checked' => time() ];
    $body = llmstxt_github_get( LLMSTXT_REMOTE_MAIN_FILE );
    if ( is_wp_error( $body ) ) {
        $info['error'] = $body->get_error_message();
    } elseif ( preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', $body, $m ) ) {
        $info['version'] = $m[1];
    } else {
        $info['error'] = 'No se encontró la línea "Version:" en ' . LLMSTXT_REMOTE_MAIN_FILE . ' del repositorio.';
    }

    set_site_transient( LLMSTXT_UPDATE_CACHE, $info, $info['error'] ? HOUR_IN_SECONDS : 6 * HOUR_IN_SECONDS );
    return $info;
}

function llmstxt_update_item( $version ) {
    $repo = llmstxt_update_repo();
    return (object) [
        'id'            => 'github.com/' . $repo,
        'slug'          => dirname( llmstxt_plugin_basename() ),
        'plugin'        => llmstxt_plugin_basename(),
        'new_version'   => $version,
        'url'           => 'https://github.com/' . $repo,
        'package'       => llmstxt_github_contents_url( LLMSTXT_REMOTE_ZIP ),
        'requires_php'  => '7.4',
        'icons'         => [],
        'banners'       => [],
        'banners_rtl'   => [],
        'compatibility' => new stdClass(),
    ];
}

// Informa a WordPress de la versión disponible.
add_filter( 'pre_set_site_transient_update_plugins', function( $transient ) {
    if ( ! is_object( $transient ) || empty( $transient->checked ) ) return $transient;

    $info = llmstxt_remote_info();
    if ( ! $info['version'] ) return $transient;

    $basename = llmstxt_plugin_basename();
    $item     = llmstxt_update_item( $info['version'] );
    if ( version_compare( $info['version'], LLMSTXT_VERSION, '>' ) ) {
        $transient->response[ $basename ] = $item;
        unset( $transient->no_update[ $basename ] );
    } else {
        // Estar en "no_update" hace que WordPress muestre el interruptor de actualizaciones automáticas.
        $transient->no_update[ $basename ] = $item;
        unset( $transient->response[ $basename ] );
    }
    return $transient;
} );

// Ventana "Ver detalles" de la página de plugins: muestra el CHANGELOG del repositorio.
add_filter( 'plugins_api', function( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( llmstxt_plugin_basename() ) !== $args->slug ) {
        return $result;
    }
    $info      = llmstxt_remote_info();
    $changelog = get_site_transient( 'llmstxt_update_changelog' );
    if ( false === $changelog ) {
        $md        = llmstxt_github_get( LLMSTXT_REMOTE_CHANGELOG );
        $changelog = is_wp_error( $md ) ? '<p>' . esc_html( $md->get_error_message() ) . '</p>' : llmstxt_markdown_to_html( $md );
        set_site_transient( 'llmstxt_update_changelog', $changelog, 6 * HOUR_IN_SECONDS );
    }
    return (object) [
        'name'          => 'LLMs.txt Generator',
        'slug'          => $args->slug,
        'version'       => $info['version'] ?: LLMSTXT_VERSION,
        'author'        => 'Tu Agencia SEO',
        'homepage'      => 'https://github.com/' . llmstxt_update_repo(),
        'requires_php'  => '7.4',
        'download_link' => llmstxt_github_contents_url( LLMSTXT_REMOTE_ZIP ),
        'sections'      => [
            'description' => '<p>Genera archivos llms.txt con OpenAI y los envía por email a tus leads.</p>',
            'changelog'   => $changelog,
        ],
    ];
}, 10, 3 );

/** Conversión mínima de Markdown (encabezados, listas, negrita, código) para el changelog. */
function llmstxt_markdown_to_html( $md ) {
    $html    = '';
    $in_list = false;
    foreach ( preg_split( '/\R/', (string) $md ) as $line ) {
        $text = esc_html( trim( $line ) );
        $text = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text );
        $text = preg_replace( '/`(.+?)`/', '<code>$1</code>', $text );
        $is_item = (bool) preg_match( '/^[-*]\s+/', trim( $line ) );
        if ( $in_list && ! $is_item ) { $html .= '</ul>'; $in_list = false; }
        if ( '' === $text ) continue;
        if ( preg_match( '/^(#{1,6})\s+(.*)$/', $text, $m ) ) {
            $level = min( 6, strlen( $m[1] ) + 1 );
            $html .= "<h{$level}>{$m[2]}</h{$level}>";
        } elseif ( $is_item ) {
            if ( ! $in_list ) { $html .= '<ul>'; $in_list = true; }
            $html .= '<li>' . preg_replace( '/^[-*]\s+/', '', $text ) . '</li>';
        } else {
            $html .= "<p>$text</p>";
        }
    }
    return $in_list ? $html . '</ul>' : $html;
}

// Si la carpeta instalada tiene otro nombre (p. ej. "llms-txt-generator-v3"), el ZIP nuevo se instala con ese mismo nombre.
add_filter( 'upgrader_source_selection', function( $source, $remote_source, $upgrader, $hook_extra = [] ) {
    global $wp_filesystem;
    if ( empty( $hook_extra['plugin'] ) || llmstxt_plugin_basename() !== $hook_extra['plugin'] ) return $source;
    $wanted = trailingslashit( $remote_source ) . dirname( llmstxt_plugin_basename() );
    if ( untrailingslashit( $source ) === $wanted ) return $source;
    if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), $wanted, true ) ) {
        return trailingslashit( $wanted );
    }
    return new WP_Error( 'llmstxt_rename', 'No se pudo preparar la carpeta del plugin para la actualización.' );
}, 10, 4 );

// Tras actualizar, olvidar la información en caché.
add_action( 'upgrader_process_complete', function( $upgrader, $options ) {
    if ( ( $options['type'] ?? '' ) === 'plugin' && in_array( llmstxt_plugin_basename(), (array) ( $options['plugins'] ?? [] ), true ) ) {
        delete_site_transient( LLMSTXT_UPDATE_CACHE );
        delete_site_transient( 'llmstxt_update_changelog' );
    }
}, 10, 2 );

// ─── Ajustes ─────────────────────────────────────────────────────────────────

add_action( 'admin_init', function() {
    register_setting( 'llmstxt_options', 'llmstxt_update_repo', [ 'sanitize_callback' => function( $v ) {
        $v = trim( sanitize_text_field( $v ) );
        $v = preg_replace( '#^https?://github\.com/#i', '', rtrim( $v, '/' ) ); // acepta la URL completa
        return '' !== $v ? $v : 'designsandit/llmstxt';
    } ] );
    register_setting( 'llmstxt_options', 'llmstxt_update_branch', [ 'sanitize_callback' => function( $v ) {
        $v = trim( sanitize_text_field( $v ) );
        return '' !== $v ? $v : 'main';
    } ] );
    register_setting( 'llmstxt_options', 'llmstxt_github_token', [ 'sanitize_callback' => function( $v ) {
        return preg_replace( '/\s+/', '', (string) $v );
    } ] );
} );

// Si el campo del token se deja vacío al guardar, conservar el existente (salvo que se marque "borrar").
add_filter( 'pre_update_option_llmstxt_github_token', function( $new, $old ) {
    if ( ! empty( $_POST['llmstxt_github_token_clear'] ) ) return '';
    return '' === $new ? $old : $new;
}, 10, 2 );

// Cambiar el origen invalida la caché. La primera vez que se guarda una opción WordPress dispara
// add_option_* (no update_option_*), así que se escuchan ambos.
function llmstxt_clear_update_cache() {
    delete_site_transient( LLMSTXT_UPDATE_CACHE );
    delete_site_transient( 'llmstxt_update_changelog' );
}
foreach ( [ 'llmstxt_update_repo', 'llmstxt_update_branch', 'llmstxt_github_token' ] as $llmstxt_opt ) {
    add_action( "add_option_{$llmstxt_opt}", 'llmstxt_clear_update_cache' );
    add_action( "update_option_{$llmstxt_opt}", 'llmstxt_clear_update_cache' );
}
unset( $llmstxt_opt );

add_action( 'llmstxt_settings_fields', function() {
    $token_in_config = defined( 'LLMSTXT_GITHUB_TOKEN' ) && LLMSTXT_GITHUB_TOKEN;
    $has_token       = '' !== llmstxt_update_token();
    ?>
    <tr><th colspan="2"><h2 style="margin:0">Actualizaciones desde GitHub</h2></th></tr>
    <tr>
        <th><label for="llmstxt_update_repo">Repositorio</label></th>
        <td>
            <input type="text" id="llmstxt_update_repo" name="llmstxt_update_repo"
                   value="<?php echo esc_attr( get_option( 'llmstxt_update_repo', 'designsandit/llmstxt' ) ); ?>" class="regular-text" />
            <p class="description">Formato <code>usuario/repositorio</code>. Rama:
                <input type="text" name="llmstxt_update_branch" value="<?php echo esc_attr( llmstxt_update_branch() ); ?>" style="width:120px" /></p>
        </td>
    </tr>
    <tr>
        <th><label for="llmstxt_github_token">Token de GitHub</label></th>
        <td>
            <?php if ( $token_in_config ) : ?>
                <p><span style="color:#16a34a">✓</span> Definido en <code>wp-config.php</code> (<code>LLMSTXT_GITHUB_TOKEN</code>).</p>
            <?php else : ?>
                <input type="password" id="llmstxt_github_token" name="llmstxt_github_token" value=""
                       placeholder="<?php echo $has_token ? esc_attr( '(configurado — pega uno nuevo para cambiarlo)' ) : 'github_pat_...'; ?>"
                       class="regular-text" autocomplete="new-password" />
                <?php if ( $has_token ) : ?>
                    <label style="margin-left:8px"><input type="checkbox" name="llmstxt_github_token_clear" value="1" /> Borrar token</label>
                <?php endif; ?>
            <?php endif; ?>
            <p class="description">Solo necesario si el repositorio es <strong>privado</strong>. Crea un token <em>fine-grained</em> en
                <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener noreferrer">github.com/settings/personal-access-tokens</a>
                con acceso solo a este repositorio y el permiso <strong>Contents: Read-only</strong>.</p>
        </td>
    </tr>
    <?php
} );

// ─── Diagnóstico ─────────────────────────────────────────────────────────────

add_action( 'llmstxt_diagnostics_rows', function( $ok, $fail, $warn ) {
    $info = get_site_transient( LLMSTXT_UPDATE_CACHE );
    echo '<tr><td>Actualizaciones (GitHub)</td><td>';
    if ( defined( 'LLMSTXT_GITHUB_TOKEN' ) && LLMSTXT_GITHUB_TOKEN ) {
        echo $ok . 'Token de GitHub definido en wp-config.php.<br>';
    } elseif ( '' !== llmstxt_update_token() ) {
        echo $ok . 'Token de GitHub configurado (termina en <code>' . esc_html( substr( llmstxt_update_token(), -4 ) ) . '</code>).<br>';
    } else {
        echo $warn . 'Sin token de GitHub (obligatorio si el repositorio es privado).<br>';
    }
    if ( ! is_array( $info ) ) {
        echo $warn . 'Aún no se ha comprobado. Pulsa "Buscar actualizaciones".';
    } elseif ( $info['error'] ) {
        echo $fail . esc_html( $info['error'] );
    } elseif ( version_compare( $info['version'], LLMSTXT_VERSION, '>' ) ) {
        echo $warn . 'Versión <code>' . esc_html( $info['version'] ) . '</code> disponible. Ve a <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a> y pulsa "Actualizar ahora".';
    } else {
        echo $ok . 'Tienes la última versión (<code>' . esc_html( $info['version'] ) . '</code> en <code>' . esc_html( llmstxt_update_repo() . '@' . llmstxt_update_branch() ) . '</code>).';
    }
    if ( is_array( $info ) ) {
        echo ' <small>(comprobado ' . esc_html( human_time_diff( $info['checked'] ) ) . ' atrás)</small>';
    }
    $auto = (array) get_site_option( 'auto_update_plugins', [] );
    echo '<br>' . ( in_array( llmstxt_plugin_basename(), $auto, true )
        ? $ok . 'Actualizaciones automáticas activadas: WordPress instalará cada versión nueva por sí solo (normalmente en menos de 12 h).'
        : $warn . 'Actualizaciones automáticas desactivadas. Para activarlas, en <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">Plugins</a> pulsa "Activar actualizaciones automáticas" en LLMs.txt Generator.' );
    echo '</td></tr>';
}, 10, 3 );

add_action( 'llmstxt_diagnostics_buttons', function() {
    echo '<a href="' . esc_url( llmstxt_action_url( 'llmstxt_check_updates' ) ) . '" class="button">Buscar actualizaciones</a> ';
} );

add_action( 'admin_post_llmstxt_check_updates', function() {
    llmstxt_check_admin_action( 'llmstxt_check_updates' );
    delete_site_transient( 'llmstxt_update_changelog' );
    $info = llmstxt_remote_info( true );
    if ( $info['error'] ) {
        llmstxt_admin_redirect( 'settings', 'error', 'No se pudo comprobar GitHub: ' . $info['error'] );
    }
    // Forzar que WordPress vuelva a consultar ahora mismo (sin esperar a su ciclo de 12 h).
    delete_site_transient( 'update_plugins' );
    wp_update_plugins();
    if ( version_compare( $info['version'], LLMSTXT_VERSION, '>' ) ) {
        llmstxt_admin_redirect( 'settings', 'warning', 'Hay una versión nueva (' . $info['version'] . '). Ve a Plugins y pulsa "Actualizar ahora".' );
    }
    llmstxt_admin_redirect( 'settings', 'success', 'Tienes la última versión (' . LLMSTXT_VERSION . ').' );
} );
