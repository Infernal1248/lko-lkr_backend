<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicSiteController extends Controller
{
    public function home(Request $request): Response
    {
        // Preserve links already sent before the cabinet moved under /app.
        if ($request->query('page') === 'privacy') {
            return $this->cabinetRedirect($request, '/app/privacy');
        }
        if ($request->has('reset_token')) {
            return $this->cabinetRedirect($request, '/app/reset-password');
        }
        if ($request->has('payment')) {
            return $this->cabinetRedirect($request, '/app/billing');
        }
        if ($request->has('push')) {
            $path = match ($request->query('push')) {
                'support' => '/app/support',
                'receipts' => '/app/receipts',
                default => '/app',
            };

            return $this->cabinetRedirect($request, $path);
        }

        return $this->page();
    }

    public function page(string $slug = ''): Response
    {
        $manifest = $this->manifest();
        if (! isset($manifest['pages'][$slug])) {
            return $this->notFound(request());
        }

        return $this->generatedResponse($manifest['pages'][$slug]);
    }

    public function cabinet(Request $request): Response
    {
        if ($request->getPathInfo() === '/app/') {
            return $this->cabinetRedirect($request, '/app');
        }
        $file = public_path('app-assets/cabinet.html');
        abort_unless(is_file($file), 503, 'Соберите Vue-приложение: npm run build в frontend.');

        return response()->file($file, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, noarchive',
            'Referrer-Policy' => 'same-origin',
        ])->setPrivate();
    }

    public function legacyCabinet(Request $request): Response
    {
        $path = $request->path();
        $target = in_array($path, ['dashboard', 'app/dashboard', 'app/index.html'], true)
            ? '/app'
            : '/app/'.$path;

        return $this->cabinetRedirect($request, $target);
    }

    public function legacyAsset(Request $request, string $asset): Response
    {
        // Cached old frontend builds may still request /app/assets or /app/help.
        abort_if(str_contains($asset, '..'), 404);
        abort_unless(is_file(public_path('app-assets/'.$asset)), 404);

        return $this->cabinetRedirect($request, '/app-assets/'.$asset);
    }

    public function legacySiteImage(Request $request, string $asset): Response
    {
        abort_if(str_contains($asset, '..'), 404);
        abort_unless(is_file(public_path('app-assets/site-images/'.$asset)), 404);

        return $this->cabinetRedirect($request, '/app-assets/site-images/'.$asset);
    }

    public function legacySiteStyle(Request $request): Response
    {
        $file = $this->manifest()['styles'][0] ?? '';
        abort_unless(preg_match('~^assets/[a-zA-Z0-9_.-]+\\.css$~', $file) && is_file(public_path('app-assets/'.$file)), 404);

        return $this->cabinetRedirect($request, '/app-assets/'.$file);
    }

    private function cabinetRedirect(Request $request, string $path): Response
    {
        $query = $request->server->get('QUERY_STRING', '');

        return redirect($path.($query !== '' ? '?'.$query : ''), 301)
            ->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, noarchive', 'Referrer-Policy' => 'same-origin']);
    }

    public function sitemap(): Response
    {
        return $this->generatedResponse('sitemap.xml')->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function notFound(Request $request): Response
    {
        if ($request->is('api', 'api/*') || $request->expectsJson()) {
            return response()->json(['message' => 'Not Found'], 404)
                ->header('Cache-Control', 'private, no-store')
                ->header('X-Robots-Tag', 'noindex, noarchive');
        }

        return $this->generatedResponse($this->manifest()['notFound'], 404)
            ->header('X-Robots-Tag', 'noindex, noarchive');
    }

    private function manifest(): array
    {
        $path = public_path('app-assets/site/manifest.json');
        abort_unless(is_file($path), 503, 'Соберите frontend: npm run build.');
        $manifest = json_decode(file_get_contents($path), true);
        abort_unless(is_array($manifest) && isset($manifest['origin'], $manifest['pages'], $manifest['notFound']), 503, 'Повреждена сборка frontend.');

        return $manifest;
    }

    private function generatedResponse(string $file, int $status = 200): Response
    {
        abort_unless(preg_match('/^[a-z0-9-]+\.(?:html|xml)$/', $file), 503);
        $path = public_path('app-assets/site/'.$file);
        abort_unless(is_file($path), 503, 'Соберите frontend: npm run build.');
        $manifest = $this->manifest();
        $html = str_replace($manifest['origin'], rtrim(config('site.url'), '/'), file_get_contents($path));

        return response($html, $status)->header('Cache-Control', 'private, no-cache');
    }
}
