# Laravel CDAC e-Hastakshar

Laravel package for preparing CDAC e-Hastakshar PDF requests, signing request XML, handling eSign responses, and storing signed PDFs.

This package intentionally does not register routes, controllers, or views. Host applications should implement their own user flow and call the package service.

## Installation

From Packagist:

```bash
composer require mozhuilungdsuo/laravel-cdac-e-hastakshar
php artisan vendor:publish --tag=cdac-e-hastakshar-config
```

### Install Requirements

This package requires the PHP Imagick extension because uploaded PDFs/images are converted to page images before the signature placeholder is prepared.

On macOS with Homebrew:

```bash
brew install imagemagick
pecl install imagick
```

On Ubuntu/Debian:

```bash
sudo apt-get install php-imagick
```

Confirm PHP can see the extension:

```bash
php -m | grep imagick
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
        $responseXml = (string) $request->input('eSignResponse', '');

        if ($responseXml === '') {
            return view('esign.result', [
                'status' => 'failed',
                'message' => 'The eSign response was empty.',
            ]);
        }

        try {
            $result = $esign->completeResponse($responseXml);
        } catch (RuntimeException $exception) {
            return view('esign.result', [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ]);
        }

        return view('esign.result', [
            'status' => 'completed',
            'transactionId' => $result['transaction_id'],
            'downloadUrl' => route('esign.download', $result['transaction_id']),
        ]);
    }

    public function download(string $transactionId, EsignService $esign)
    {
        return $esign->signedDownloadResponse($transactionId);
    }
}
```

Create `resources/views/esign/index.blade.php` in the host app for the upload form:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('eSign document') }}</title>
    </head>
    <body>
        <main style="max-width: 720px; margin: 48px auto; font-family: sans-serif;">
            <h1>{{ __('eSign document') }}</h1>
            <p>{{ __('Upload a PDF or image to prepare it for CDAC e-Hastakshar.') }}</p>

            <form method="POST" action="{{ route('esign.store') }}" enctype="multipart/form-data">
                @csrf

                <div>
                    <label for="document">{{ __('Document') }}</label>
                    <input
                        id="document"
                        type="file"
                        name="document"
                        accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
                        required
                    >
                </div>

                @error('document')
                    <p style="color: #b91c1c;">{{ $message }}</p>
                @enderror

                <button type="submit" style="margin-top: 16px;">
                    {{ __('Start eSign') }}
                </button>
            </form>
        </main>
    </body>
</html>
```

Create `resources/views/esign/redirect.blade.php` in the host app to submit the generated request to CDAC:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('Redirecting to eSign') }}</title>
    </head>
    <body>
        <form action="{{ $endpoint }}" method="post" id="esign-request-form">
            <input type="hidden" id="eSignRequest" name="eSignRequest" value="{{ $request_xml }}">
            <input type="hidden" id="aspTxnID" name="aspTxnID" value="{{ $txn }}">
            <input type="hidden" id="Content-Type" name="Content-Type" value="application/xml">
            <noscript>
                <button type="submit">{{ __('Continue to eSign') }}</button>
            </noscript>
        </form>

        <script>
            document.getElementById('esign-request-form').submit();
        </script>
    </body>
</html>
```

Create `resources/views/esign/result.blade.php` in the host app for success/failure responses:

```blade
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ __('eSign result') }}</title>
    </head>
    <body>
        <main style="max-width: 720px; margin: 48px auto; font-family: sans-serif;">
            @if ($status === 'completed')
                <h1>{{ __('eSign completed') }}</h1>
                <p>{{ __('The signed PDF has been saved and is ready to download.') }}</p>
                <p>{{ __('Transaction') }}: {{ $transactionId }}</p>
                <a href="{{ $downloadUrl }}">{{ __('Download') }}</a>
            @else
                <h1>{{ __('eSign failed') }}</h1>
                <p>{{ $message }}</p>
            @endif
        </main>
    </body>
</html>
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
