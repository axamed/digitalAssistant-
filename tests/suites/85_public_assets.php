<?php
declare(strict_types=1);

/**
 * Asset resolution for the public pages.
 *
 * A wrong relative URL in a stylesheet returns 404 and fails silently in the browser: the page
 * still renders, just without its artwork. These checks resolve each reference against the file
 * system, the way a browser resolves it, and confirm the target exists. When Apache is running
 * the same references are also requested over HTTP, so a real 200 is confirmed too.
 */

use App\Core\Config;

$publicDir = dirname(__DIR__, 2) . '/public';
$cssDir   = $publicDir . '/assets/css';
$cssFile  = $cssDir . '/app.css';

/** Resolve a reference the way a browser resolves it against the referring file's directory. */
$resolve = static function (string $referrerDir, string $reference): ?string {
    if (preg_match('~^(https?:)?//~', $reference) || str_starts_with($reference, 'data:')) {
        return null;
    }
    $reference = explode('#', $reference, 2)[0];
    $reference = explode('?', $reference, 2)[0];
    if ($reference === '') {
        return null;
    }
    $path = $referrerDir;
    foreach (explode('/', $reference) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            $path = dirname($path);
            continue;
        }
        $path .= '/' . $segment;
    }
    return realpath($path) ?: $path;
};

/** Best-effort HTTP status for a project-absolute path, or null when no server is reachable. */
$httpStatus = static function (string $publicPath): ?int {
    $url = 'http://localhost/civil-registration/public/' . ltrim(str_replace('\\', '/', $publicPath), '/');
    $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $header, $m)) {
            return (int) $m[1];
        }
    }
    return null;
};

/* ── The stylesheet ──────────────────────────────────────────────────────────────── */

echo "\nStylesheet Asset Tests\n";

check('the stylesheet exists on disk', is_file($cssFile), $cssFile);

$css = (string) file_get_contents($cssFile);
preg_match_all('~url\(\s*([\'"]?)([^\'")]+)\1\s*\)~', $css, $urlMatches);
$references = array_values(array_unique($urlMatches[2]));
check('the stylesheet references at least one image', $references !== []);

$broken = [];
$http = null;
foreach ($references as $reference) {
    $resolved = $resolve($cssDir, $reference);
    if ($resolved === null) {
        continue;   // absolute or data URL, nothing to check locally
    }
    if (!is_file($resolved)) {
        $broken[] = "url('$reference') resolves to $resolved, which does not exist";
        continue;
    }
    $status = $httpStatus(str_replace('\\', '/', substr($resolved, strlen(realpath($publicDir)) + 1)));
    if ($status !== null) {
        $http = $status;
        if ($status !== 200) {
            $broken[] = "url('$reference') returns HTTP $status";
        }
    }
}
check(
    'every url() in the stylesheet resolves to a real file',
    $broken === [],
    implode('; ', $broken)
);

// The specific regression: 'img/cover.svg' resolves under assets/css/img/, which does not
// exist, so the backdrop silently never loads. It has to be written as '../img/cover.svg'.
$svgReferences = array_values(array_filter($references, static fn(string $r): bool => str_ends_with($r, '.svg')));
check(
    "the cover backdrop is referenced as '../img/cover.svg'",
    in_array('../img/cover.svg', $svgReferences, true),
    'found: ' . implode(', ', $svgReferences)
);
$bareImagePaths = array_values(array_filter(
    $references,
    static fn(string $r): bool => (bool) preg_match('~^img/~', $r)
));
check(
    "no stylesheet reference uses a bare 'img/...' path",
    $bareImagePaths === [],
    'these resolve under assets/css/img/ and would 404: ' . implode(', ', $bareImagePaths)
);

check(
    'the cover artwork itself is present',
    is_file($publicDir . '/assets/img/cover.svg')
);
check(
    'the cover artwork is a real SVG document',
    str_contains((string) file_get_contents($publicDir . '/assets/img/cover.svg'), '<svg')
);
if ($http !== null) {
    check('the stylesheet is served over HTTP', $http === 200, "status $http");
}

/* ── The cover photographs ───────────────────────────────────────────────────────── */

echo "\nCover Photograph Tests\n";

$pages = ['index.php', 'about.php', 'contact.php'];
$photos = [];
foreach ($pages as $page) {
    $file = $publicDir . '/' . $page;
    check("$page exists", is_file($file), $file);
    $html = (string) file_get_contents($file);
    preg_match_all('~assets/img/([A-Za-z0-9 .()_-]+\.(?:jpg|jpeg|png|jfif))~', $html, $found);
    foreach ($found[1] as $photo) {
        $photos[$photo] = $page;
    }
}
check('the public pages reference the cover photographs', $photos !== [], 'no photo references found');

foreach ($photos as $photo => $page) {
    check("the photo $photo used by $page exists", is_file($publicDir . '/assets/img/' . $photo));
}

/* ── Everything the contact page pulls in, layout included ───────────────────────── */

echo "\nContact Page Asset Tests\n";

// The page itself only holds its own markup; the stylesheet comes from the shared layout, so
// the page is rendered to resolve the asset URLs exactly as a visitor receives them.
$rendered = runSnippet(
    '$_SERVER["REQUEST_METHOD"] = "GET";' . "\n"
    . '$_SERVER["REQUEST_URI"] = "/contact.php";' . "\n"
    . '$_GET = [];' . "\n"
    . 'ob_start();' . "\n"
    . 'require ' . var_export(dirname(__DIR__, 2) . '/public/contact.php', true) . ';' . "\n"
    . 'file_put_contents(SNIPPET_OUT, (string) ob_get_clean());',
    false
);
$contactHtml = $rendered['file'] !== '' ? $rendered['file'] : $rendered['stdout'];
check('the contact page renders', $contactHtml !== '', $rendered['stdout']);
check('the rendered contact page has no PHP error', !str_contains($contactHtml, 'Fatal error') && !str_contains($contactHtml, 'Uncaught'));

preg_match_all('~(?:href|src)="([^"]+\.(?:css|js|png|jpg|jpeg|svg|ico|webmanifest))"~', $contactHtml, $links);
$assets = array_values(array_unique($links[1]));
$stylesheetLinked = false;
foreach ($assets as $link) {
    if (str_ends_with($link, '/assets/css/app.css')) {
        $stylesheetLinked = true;
    }
}
check('the contact page links the stylesheet', $stylesheetLinked, implode(', ', $assets));

// asset() prefixes every URL with app.base_url, which is the URL path of public/. So a link
// arrives as '/civil-registration/public/assets/css/app.css' when the app is served from a
// subdirectory. Strip that prefix and what remains is relative to public/.
$baseUrl = rtrim((string) Config::get('app.base_url', ''), '/');
$missing = [];
foreach ($assets as $link) {
    if (preg_match('~^(https?:)?//|^#~', $link)) {
        continue;
    }
    $relative = $link;
    if ($baseUrl !== '' && str_starts_with($relative, $baseUrl . '/')) {
        $relative = substr($relative, strlen($baseUrl) + 1);
    }
    if (str_starts_with($relative, '/') || str_contains($relative, '../')) {
        $missing[] = "$link does not resolve inside the public directory";
        continue;
    }
    if (!is_file($resolve($publicDir, $relative))) {
        $missing[] = $link;
    }
}
check('every asset the contact page links to exists', $missing === [], implode(', ', $missing));

// The QR code is inlined rather than linked, so it is checked as markup on the page.
check('the contact page inlines the QR code', str_contains($contactHtml, '<svg xmlns="http://www.w3.org/2000/svg"'));
check('the contact page links WhatsApp over HTTPS', (bool) preg_match('~href="https://wa\.me/\d{9,15}\?text=[^"]+"~', $contactHtml));

/* ── The WhatsApp QR on the served page ──────────────────────────────────────────── */

echo "\nServed Contact Page Tests\n";

$status = $httpStatus('contact.php');
if ($status === null) {
    // No web server in this environment; the render above still covers the markup.
    check('the contact page response was not checked because no server is running', true);
} else {
    check('the contact page is served', $status === 200, "status $status");
    $url = 'http://localhost/civil-registration/public/contact.php';
    $body = (string) @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
    check('the served contact page runs without a PHP error', !str_contains($body, 'Fatal error') && !str_contains($body, 'Uncaught'));
    check('the served contact page shows the WhatsApp card', str_contains($body, 'whatsapp-card'));
    check('the served contact page inlines the QR code', str_contains($body, '<svg xmlns="http://www.w3.org/2000/svg"'));

    // The served stylesheet must carry the corrected backdrop path, since that is what the
    // browser actually receives.
    $cssUrl = 'http://localhost/civil-registration/public/assets/css/app.css';
    $servedCss = (string) @file_get_contents($cssUrl, false, stream_context_create(['http' => ['timeout' => 5]]));
    check(
        'the served stylesheet points the backdrop at ../img/cover.svg',
        str_contains($servedCss, "url('../img/cover.svg')")
    );
    check(
        'the served stylesheet has no bare img/ path',
        !preg_match("~url\(['\"]img/~", $servedCss)
    );
}