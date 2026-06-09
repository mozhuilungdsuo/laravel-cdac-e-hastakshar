# Laravel CDAC e-Hastakshar

Laravel package for preparing CDAC e-Hastakshar PDF requests, signing request XML, handling eSign responses, and storing signed PDFs.

This package intentionally does not register routes, controllers, or views. Host applications should implement their own user flow and call the package service.

## Installation

From Packagist:

```bash
composer require mozhuilungdsuo/laravel-cdac-e-hastakshar
php artisan vendor:publish --tag=cdac-e-hastakshar-config
```

For local development from a Laravel app:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/laravel-cdac-e-hastakshar",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

Then require the package:

```bash
composer require mozhuilungdsuo/laravel-cdac-e-hastakshar:^1.0
```

## Keys

Private keys and certificates are intentionally not shipped with the package. Add them to the host app, commonly:

```txt
keys/
  eSign_Staging_Private.key
```

Configure the path:

```env
ESIGN_PRIVATE_KEY=keys/eSign_Staging_Private.key
ESIGN_PRIVATE_KEY_PASSPHRASE=
```

## Usage

Inject `Mozhuilungdsuo\LaravelCdacEHastakshar\Services\EsignService` in your own controller.

```php
use Illuminate\Http\Request;
use Mozhuilungdsuo\LaravelCdacEHastakshar\Services\EsignService;
use RuntimeException;

class EsignController
{
    public function store(Request $request, EsignService $esign)
    {
        $validated = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:20480'],
        ]);

        $payload = $esign->createRequest($validated['document']);

        return view('esign.redirect', $payload);
    }

    public function response(Request $request, EsignService $esign)
    {
        $result = $esign->completeResponse((string) $request->input('eSignResponse', ''));

        return redirect()->route('esign.download', $result['transaction_id']);
    }

    public function download(string $transactionId, EsignService $esign)
    {
        return $esign->signedDownloadResponse($transactionId);
    }
}
```

Example host-app routes:

```php
use App\Http\Controllers\EsignController;
use Illuminate\Support\Facades\Route;

Route::get('esign', [EsignController::class, 'index'])->name('esign.index');
Route::post('esign', [EsignController::class, 'store'])->name('esign.store');
Route::post('esign/response', [EsignController::class, 'response'])->name('esign.response');
Route::get('esign/{transactionId}/download', [EsignController::class, 'download'])->name('esign.download');
```

For Laravel's application bootstrap middleware configuration, exclude the callback route from CSRF validation:

```php
$middleware->validateCsrfTokens(except: [
    'esign/response',
]);
```

## Dependencies

The package declares its runtime dependencies in `composer.json`, including:

- `robrichards/xmlseclibs`
- `tecnickcom/tc-lib-pdf`
- `tecnickcom/tc-font-mirror`
- `ext-imagick`

Composer will install those dependencies when this package is installed.
