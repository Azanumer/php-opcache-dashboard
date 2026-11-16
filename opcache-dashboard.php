<?php
/**
 * OPcache Dashboard — single-file OPcache status + reset tool.
 *
 * Drop this file anywhere in your web root (e.g. /opcache-dashboard.php),
 * open it in a browser and see memory usage, hit rate, cached scripts and
 * restart counters at a glance. No framework, no database, no install.
 *
 * SECURITY — this page exposes server internals, so it is locked down in
 * two independent ways:
 *
 *  1. IP allow-list: only the addresses in OPCACHE_DASHBOARD_ALLOWED_IPS
 *     may open it (localhost by default). Everyone else gets HTTP 403.
 *  2. Action token: the Reset / Invalidate actions additionally require
 *     ?token=... to match OPCACHE_DASHBOARD_TOKEN. Leave the token empty
 *     and all write actions are disabled entirely.
 *
 * Set both constants below before deploying to a shared server, and delete
 * the file (or move it out of the web root) when you no longer need it.
 */

define( 'OPCACHE_DASHBOARD_ALLOWED_IPS', array( '127.0.0.1', '::1' ) );
define( 'OPCACHE_DASHBOARD_TOKEN', '' ); // e.g. 'a-long-random-string' — empty disables write actions.

/* ------------------------------------------------------------------ */
/*  Access control                                                     */
/* ------------------------------------------------------------------ */

function opd_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
}

function opd_forbidden( $message ) {
	http_response_code( 403 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo $message;
	exit;
}

if ( ! in_array( opd_client_ip(), OPCACHE_DASHBOARD_ALLOWED_IPS, true ) ) {
	opd_forbidden( "Forbidden: your IP (" . opd_client_ip() . ") is not in the dashboard allow-list.\n" );
}

function opd_token_valid() {
	$token = defined( 'OPCACHE_DASHBOARD_TOKEN' ) ? OPCACHE_DASHBOARD_TOKEN : '';
	if ( '' === $token ) {
		return false;
	}
	$given = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';
	return hash_equals( $token, $given );
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function opd_format_bytes( $bytes ) {
	$bytes = (float) $bytes;
	foreach ( array( 'B', 'KB', 'MB', 'GB' ) as $unit ) {
		if ( $bytes < 1024 ) {
			return round( $bytes, 1 ) . ' ' . $unit;
		}
		$bytes /= 1024;
	}
	return round( $bytes, 1 ) . ' TB';
}

function opd_format_number( $n ) {
	return number_format( (float) $n );
}

function opd_pct( $part, $whole ) {
	if ( $whole <= 0 ) {
		return '0%';
	}
	return round( $part / $whole * 100, 1 ) . '%';
}

function opd_e( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

/* ------------------------------------------------------------------ */
/*  Data                                                               */
/* ------------------------------------------------------------------ */

if ( ! function_exists( 'opcache_get_status' ) ) {
	http_response_code( 503 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "OPcache is not available: the Zend OPcache extension is not loaded.\n";
	exit;
}

$status = opcache_get_status( true ); // true = include the cached-scripts list.
if ( false === $status || empty( $status['opcache_enabled'] ) ) {
	http_response_code( 503 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "OPcache is loaded but not enabled (opcache.enable is off).\n";
	exit;
}

$config = opcache_get_configuration();
$stats  = isset( $status['opcache_statistics'] ) ? $status['opcache_statistics'] : array();
$memory = isset( $status['memory_usage'] ) ? $status['memory_usage'] : array();
$interned = isset( $status['interned_strings_usage'] ) ? $status['interned_strings_usage'] : array();
$scripts  = isset( $status['scripts'] ) ? $status['scripts'] : array();

/* ------------------------------------------------------------------ */
/*  Actions (token-protected)                                          */
/* ------------------------------------------------------------------ */

$notice = '';
$action = isset( $_GET['action'] ) ? (string) $_GET['action'] : '';
if ( '' !== $action ) {
	if ( ! opd_token_valid() ) {
		opd_forbidden( "Forbidden: write actions require a valid ?token= value (set OPCACHE_DASHBOARD_TOKEN first).\n" );
	}
	if ( 'reset' === $action ) {
		$ok     = opcache_reset();
		$notice = $ok ? 'OPcache reset successfully.' : 'OPcache reset failed.';
		header( 'Location: ' . strtok( $_SERVER['REQUEST_URI'], '?' ) . '?notice=' . rawurlencode( $notice ) );
		exit;
	}
	if ( 'invalidate' === $action && isset( $_GET['file'] ) ) {
		$file = (string) $_GET['file'];
		$ok   = opcache_invalidate( $file, true );
		$notice = $ok ? 'Invalidated: ' . $file : 'Could not invalidate: ' . $file;
		header( 'Location: ' . strtok( $_SERVER['REQUEST_URI'], '?' ) . '?notice=' . rawurlencode( $notice ) );
		exit;
	}
}
if ( isset( $_GET['notice'] ) ) {
	$notice = (string) $_GET['notice'];
}

/* ------------------------------------------------------------------ */
/*  JSON mode (for monitoring scripts — same IP allow-list applies)    */
/* ------------------------------------------------------------------ */

if ( isset( $_GET['format'] ) && 'json' === $_GET['format'] ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode(
		array(
			'opcache_enabled'   => true,
			'cache_full'        => ! empty( $status['cache_full'] ),
			'restart_pending'   => ! empty( $status['restart_pending'] ),
			'memory_used'       => isset( $memory['used_memory'] ) ? (int) $memory['used_memory'] : 0,
			'memory_free'       => isset( $memory['free_memory'] ) ? (int) $memory['free_memory'] : 0,
			'memory_wasted'     => isset( $memory['wasted_memory'] ) ? (int) $memory['wasted_memory'] : 0,
			'hit_rate'          => isset( $stats['opcache_hit_rate'] ) ? (float) $stats['opcache_hit_rate'] : 0,
			'hits'              => isset( $stats['hits'] ) ? (int) $stats['hits'] : 0,
			'misses'            => isset( $stats['misses'] ) ? (int) $stats['misses'] : 0,
			'cached_scripts'    => isset( $stats['num_cached_scripts'] ) ? (int) $stats['num_cached_scripts'] : 0,
			'cached_keys'       => isset( $stats['num_cached_keys'] ) ? (int) $stats['num_cached_keys'] : 0,
			'max_cached_keys'   => isset( $stats['max_cached_keys'] ) ? (int) $stats['max_cached_keys'] : 0,
			'oom_restarts'      => isset( $stats['oom_restarts'] ) ? (int) $stats['oom_restarts'] : 0,
			'manual_restarts'   => isset( $stats['manual_restarts'] ) ? (int) $stats['manual_restarts'] : 0,
			'start_time'        => isset( $stats['start_time'] ) ? (int) $stats['start_time'] : 0,
			'last_restart_time' => isset( $stats['last_restart_time'] ) ? (int) $stats['last_restart_time'] : 0,
			'version'           => isset( $config['version']['version'] ) ? $config['version']['version'] : '',
		),
		JSON_PRETTY_PRINT
	);
	exit;
}

/* ------------------------------------------------------------------ */
/*  Script list: filter + sort                                         */
/* ------------------------------------------------------------------ */

$search = isset( $_GET['q'] ) ? trim( (string) $_GET['q'] ) : '';
$sort   = isset( $_GET['sort'] ) ? (string) $_GET['sort'] : 'hits';
$rows   = array_values( $scripts );
if ( '' !== $search ) {
	$rows = array_values(
		array_filter(
			$rows,
			function ( $s ) use ( $search ) {
				return false !== stripos( isset( $s['full_path'] ) ? $s['full_path'] : '', $search );
			}
		)
	);
}
usort(
	$rows,
	function ( $a, $b ) use ( $sort ) {
		switch ( $sort ) {
			case 'memory':
				return ( isset( $b['memory_consumption'] ) ? $b['memory_consumption'] : 0 ) <=> ( isset( $a['memory_consumption'] ) ? $a['memory_consumption'] : 0 );
			case 'path':
				return strcmp( isset( $a['full_path'] ) ? $a['full_path'] : '', isset( $b['full_path'] ) ? $b['full_path'] : '' );
			case 'hits':
			default:
				return ( isset( $b['hits'] ) ? $b['hits'] : 0 ) <=> ( isset( $a['hits'] ) ? $a['hits'] : 0 );
		}
	}
);

$used    = isset( $memory['used_memory'] ) ? (float) $memory['used_memory'] : 0;
$free    = isset( $memory['free_memory'] ) ? (float) $memory['free_memory'] : 0;
$wasted  = isset( $memory['wasted_memory'] ) ? (float) $memory['wasted_memory'] : 0;
$total   = $used + $free + $wasted;
$hitRate = isset( $stats['opcache_hit_rate'] ) ? (float) $stats['opcache_hit_rate'] : 0;
$token   = defined( 'OPCACHE_DASHBOARD_TOKEN' ) ? OPCACHE_DASHBOARD_TOKEN : '';
$self    = strtok( $_SERVER['REQUEST_URI'], '?' );
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>OPcache Dashboard</title>
<style>
	body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f6f7f9; color: #1d2327; }
	.wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 48px; }
	h1 { font-size: 22px; margin: 0 0 4px; }
	.sub { color: #646970; margin: 0 0 20px; }
	.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 20px; }
	.card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; }
	.card .label { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #646970; }
	.card .value { font-size: 24px; font-weight: 700; margin-top: 4px; }
	.card .detail { font-size: 13px; color: #646970; margin-top: 2px; }
	.bar { height: 12px; background: #e5e7eb; border-radius: 6px; overflow: hidden; display: flex; margin: 6px 0 16px; }
	.bar .used { background: #2271b1; }
	.bar .wasted { background: #d63638; }
	.warn { background: #fcf9e8; border: 1px solid #dba617; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
	.notice { background: #edfaef; border: 1px solid #00a32a; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
	table { width: 100%; border-collapse: collapse; background: #fff; font-size: 13px; }
	th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; }
	th { background: #f0f0f1; }
	td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
	code { font-size: 12px; word-break: break-all; }
	.toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 12px; }
	.toolbar input[type="search"] { padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; min-width: 260px; }
	.btn { display: inline-block; padding: 6px 12px; border-radius: 4px; border: 1px solid #2271b1; background: #2271b1; color: #fff; text-decoration: none; font-size: 13px; }
	.btn.danger { background: #d63638; border-color: #d63638; }
	.btn.disabled { opacity: .45; pointer-events: none; }
	.muted { color: #646970; font-size: 13px; }
</style>
</head>
<body>
<div class="wrap">
	<h1>OPcache Dashboard</h1>
	<p class="sub">OPcache <?php echo opd_e( isset( $config['version']['version'] ) ? $config['version']['version'] : '' ); ?> &middot; PHP <?php echo opd_e( PHP_VERSION ); ?> &middot; started <?php echo opd_e( isset( $stats['start_time'] ) ? date( 'Y-m-d H:i:s', (int) $stats['start_time'] ) : '—' ); ?></p>

	<?php if ( '' !== $notice ) : ?>
		<div class="notice"><?php echo opd_e( $notice ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $status['cache_full'] ) ) : ?>
		<div class="warn"><strong>Cache is full.</strong> OPcache has no free memory left; new scripts will not be cached until memory is freed or the cache is reset.</div>
	<?php endif; ?>
	<?php if ( ! empty( $status['restart_pending'] ) ) : ?>
		<div class="warn"><strong>Restart pending.</strong> OPcache is waiting to restart (usually because it ran out of memory).</div>
	<?php endif; ?>

	<div class="cards">
		<div class="card">
			<div class="label">Hit rate</div>
			<div class="value"><?php echo opd_e( round( $hitRate, 2 ) ); ?>%</div>
			<div class="detail"><?php echo opd_e( opd_format_number( isset( $stats['hits'] ) ? $stats['hits'] : 0 ) ); ?> hits &middot; <?php echo opd_e( opd_format_number( isset( $stats['misses'] ) ? $stats['misses'] : 0 ) ); ?> misses</div>
		</div>
		<div class="card">
			<div class="label">Memory used</div>
			<div class="value"><?php echo opd_e( opd_format_bytes( $used ) ); ?></div>
			<div class="detail"><?php echo opd_e( opd_pct( $used, $total ) ); ?> of <?php echo opd_e( opd_format_bytes( $total ) ); ?> &middot; wasted <?php echo opd_e( opd_format_bytes( $wasted ) ); ?></div>
		</div>
		<div class="card">
			<div class="label">Cached scripts</div>
			<div class="value"><?php echo opd_e( opd_format_number( isset( $stats['num_cached_scripts'] ) ? $stats['num_cached_scripts'] : 0 ) ); ?></div>
			<div class="detail"><?php echo opd_e( opd_format_number( isset( $stats['num_cached_keys'] ) ? $stats['num_cached_keys'] : 0 ) ); ?> / <?php echo opd_e( opd_format_number( isset( $stats['max_cached_keys'] ) ? $stats['max_cached_keys'] : 0 ) ); ?> keys</div>
		</div>
		<div class="card">
			<div class="label">Interned strings</div>
			<div class="value"><?php echo opd_e( opd_format_bytes( isset( $interned['used_memory'] ) ? $interned['used_memory'] : 0 ) ); ?></div>
			<div class="detail"><?php echo opd_e( opd_format_number( isset( $interned['number_of_strings'] ) ? $interned['number_of_strings'] : 0 ) ); ?> strings</div>
		</div>
		<div class="card">
			<div class="label">Restarts</div>
			<div class="value"><?php echo opd_e( opd_format_number( isset( $stats['manual_restarts'] ) ? $stats['manual_restarts'] : 0 ) ); ?> manual</div>
			<div class="detail">OOM <?php echo opd_e( opd_format_number( isset( $stats['oom_restarts'] ) ? $stats['oom_restarts'] : 0 ) ); ?> &middot; hash <?php echo opd_e( opd_format_number( isset( $stats['hash_restarts'] ) ? $stats['hash_restarts'] : 0 ) ); ?> &middot; last <?php echo opd_e( ! empty( $stats['last_restart_time'] ) ? date( 'Y-m-d H:i:s', (int) $stats['last_restart_time'] ) : 'never' ); ?></div>
		</div>
	</div>

	<div class="bar" title="Used <?php echo opd_e( opd_pct( $used, $total ) ); ?>, wasted <?php echo opd_e( opd_pct( $wasted, $total ) ); ?>">
		<div class="used" style="width: <?php echo opd_e( $total > 0 ? round( $used / $total * 100, 2 ) : 0 ); ?>%"></div>
		<div class="wasted" style="width: <?php echo opd_e( $total > 0 ? round( $wasted / $total * 100, 2 ) : 0 ); ?>%"></div>
	</div>

	<div class="toolbar">
		<form method="get" action="<?php echo opd_e( $self ); ?>" style="display: contents;">
			<input type="search" name="q" value="<?php echo opd_e( $search ); ?>" placeholder="Filter scripts by path…" />
			<select name="sort">
				<option value="hits" <?php echo 'hits' === $sort ? 'selected' : ''; ?>>Sort: most hits</option>
				<option value="memory" <?php echo 'memory' === $sort ? 'selected' : ''; ?>>Sort: most memory</option>
				<option value="path" <?php echo 'path' === $sort ? 'selected' : ''; ?>>Sort: path A–Z</option>
			</select>
			<button type="submit" class="btn">Apply</button>
		</form>
		<?php if ( '' === $token ) : ?>
			<span class="btn danger disabled" title="Set OPCACHE_DASHBOARD_TOKEN to enable">Reset cache (disabled — no token set)</span>
		<?php else : ?>
			<a class="btn danger" href="<?php echo opd_e( $self ); ?>?action=reset&amp;token=<?php echo opd_e( $token ); ?>" onclick="return confirm('Reset the entire OPcache? Every script will be recompiled on its next request.');">Reset cache</a>
		<?php endif; ?>
		<a class="btn" href="<?php echo opd_e( $self ); ?>?format=json">JSON</a>
	</div>

	<p class="muted"><?php echo opd_e( count( $rows ) ); ?> scripts<?php echo '' !== $search ? ' matching "' . opd_e( $search ) . '"' : ''; ?>.</p>

	<table>
		<thead>
			<tr>
				<th>Script</th>
				<th class="num">Hits</th>
				<th class="num">Memory</th>
				<th>Last used</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $script ) : ?>
				<tr>
					<td><code><?php echo opd_e( isset( $script['full_path'] ) ? $script['full_path'] : '' ); ?></code></td>
					<td class="num"><?php echo opd_e( opd_format_number( isset( $script['hits'] ) ? $script['hits'] : 0 ) ); ?></td>
					<td class="num"><?php echo opd_e( opd_format_bytes( isset( $script['memory_consumption'] ) ? $script['memory_consumption'] : 0 ) ); ?></td>
					<td><?php echo opd_e( ! empty( $script['last_used_timestamp'] ) ? date( 'Y-m-d H:i:s', (int) $script['last_used_timestamp'] ) : '—' ); ?></td>
					<td>
						<?php if ( '' !== $token ) : ?>
							<a href="<?php echo opd_e( $self ); ?>?action=invalidate&amp;file=<?php echo rawurlencode( isset( $script['full_path'] ) ? $script['full_path'] : '' ); ?>&amp;token=<?php echo opd_e( $token ); ?>">Invalidate</a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5">No cached scripts<?php echo '' !== $search ? ' matching this filter' : ''; ?>.</td></tr>
			<?php endif; ?>
		</tbody>
	</table>

	<p class="muted">Directives: memory_consumption=<?php echo opd_e( isset( $config['directives']['opcache.memory_consumption'] ) ? $config['directives']['opcache.memory_consumption'] : '—' ); ?> &middot; max_accelerated_files=<?php echo opd_e( isset( $config['directives']['opcache.max_accelerated_files'] ) ? $config['directives']['opcache.max_accelerated_files'] : '—' ); ?> &middot; revalidate_freq=<?php echo opd_e( isset( $config['directives']['opcache.revalidate_freq'] ) ? $config['directives']['opcache.revalidate_freq'] : '—' ); ?>s &middot; validate_timestamps=<?php echo opd_e( ! empty( $config['directives']['opcache.validate_timestamps'] ) ? 'on' : 'off' ); ?> &middot; blacklist=<?php echo opd_e( ! empty( $config['blacklist'] ) ? implode( ', ', $config['blacklist'] ) : 'none' ); ?></p>
</div>
</body>
</html>
