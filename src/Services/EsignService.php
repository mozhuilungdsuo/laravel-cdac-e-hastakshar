<?php

namespace Mozhuilungdsuo\LaravelCdacEHastakshar\Services;

use Com\Tecnick\File\File as TecnickFile;
use Com\Tecnick\Pdf\Font\Import as FontImport;
use DOMDocument;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Imagick;
use ImagickPixel;
use Mozhuilungdsuo\LaravelCdacEHastakshar\Support\EsignPdf;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use RuntimeException;
use SimpleXMLElement;

class EsignService
{
    /**
     * @return array{txn: string, request_xml: string, endpoint: string}
     */
    public function createRequest(UploadedFile $document, ?string $signerName = null): array
    {
        $this->ensurePrivateKeyExists();

        $transactionId = Str::random(32);
        $directory = $this->transactionDirectory($transactionId);
        $originalName = pathinfo($document->getClientOriginalName(), PATHINFO_FILENAME) ?: 'document';
        $extension = strtolower($document->getClientOriginalExtension() ?: 'pdf');
        $sourcePath = "{$directory}/source.{$extension}";
        $unsignedPath = "{$directory}/unsigned-{$originalName}.pdf";

        $this->disk()->putFileAs($directory, $document, basename($sourcePath));

        $absoluteSourcePath = $this->absolutePath($sourcePath);
        $absoluteUnsignedPath = $this->absolutePath($unsignedPath);
        $prepared = $this->prepareUnsignedPdf($absoluteSourcePath, $absoluteUnsignedPath, $signerName);
        $this->disk()->delete($sourcePath);

        $txn = "{$transactionId}----{$prepared['byte_range'][1]}";

        $metadata = [
            'unsigned_path' => $unsignedPath,
            'signed_path' => "{$directory}/signed-{$originalName}.pdf",
            'byte_range' => $prepared['byte_range'],
        ];

        $this->disk()->put("{$directory}/metadata.json", json_encode($metadata, JSON_PRETTY_PRINT));

        return [
            'txn' => $txn,
            'request_xml' => $this->signedRequestXml($txn, $prepared['hash']),
            'endpoint' => (string) config('esign.endpoint'),
        ];
    }

    /**
     * @return array{transaction_id: string}
     */
    public function completeResponse(string $responseXml): array
    {
        $xml = $this->parseResponse($responseXml);
        $attributes = $xml->attributes();
        $errCode = (string) ($attributes['errCode'] ?? '');

        if ($errCode !== 'NA') {
            $message = (string) ($attributes['errMsg'] ?? '');
            throw new RuntimeException($message !== '' ? $message : "eSign Request Canceled.[#{$errCode}]");
        }

        $txn = (string) ($attributes['txn'] ?? '');
        [$transactionId, $byteRange] = $this->parseTxn($txn);
        $metadata = $this->metadata($transactionId);

        $pkcs7Value = (string) ($xml->Signatures->DocSignature ?? '');
        $certificateValue = (string) ($xml->UserX509Certificate ?? '');

        if ($pkcs7Value === '' || $certificateValue === '') {
            throw new RuntimeException('The eSign response did not include the document signature or user certificate.');
        }

        $unsignedPdf = $this->disk()->get($metadata['unsigned_path']);
        $preparedByteRange = $this->metadataByteRange($metadata, $unsignedPdf);
        $this->assertMatchingByteRange($preparedByteRange, (int) $byteRange);
        $this->disk()->put(
            $metadata['signed_path'],
            $this->newPdf()->applyExternalSignature(
                preparedPdf: $unsignedPdf,
                byteRange: $preparedByteRange,
                signature: $pkcs7Value,
                encoding: 'base64',
            ),
        );

        $this->disk()->delete($metadata['unsigned_path']);

        return [
            'transaction_id' => $transactionId,
        ];
    }

    public function signedDownloadResponse(string $transactionId)
    {
        $metadata = $this->metadata($transactionId);

        if (! $this->disk()->exists($metadata['signed_path'])) {
            throw new RuntimeException('Signed document is not available for this transaction.');
        }

        return Storage::disk((string) config('esign.storage_disk'))
            ->download($metadata['signed_path'], basename($metadata['signed_path']));
    }

    /**
     * @return array{byte_range: array{0: int, 1: int, 2: int, 3: int}, hash: string}
     */
    private function prepareUnsignedPdf(string $sourcePath, string $unsignedPath, ?string $signerName): array
    {
        $tempDirectory = dirname($unsignedPath).'/pages';

        if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0755, true) && ! is_dir($tempDirectory)) {
            throw new RuntimeException("Unable to create eSign temp directory: {$tempDirectory}");
        }

        $pageImages = $this->sourceToImages($sourcePath, $tempDirectory);
        $pdf = $this->newPdf();
        $pdf->setCreator((string) config('app.name', 'Laravel'));
        $pdf->setAuthor((string) config('app.name', 'Laravel'));
        $pdf->setSubject('Aadhaar eSign document');
        $pdf->setTitle('Aadhaar eSign document');
        $pdf->setPDFFilename(basename($unsignedPath));
        $pdf->setSignatureForExternalSigning([
            'cert_type' => 2,
            'info' => [],
            'password' => '',
            'privkey' => '',
            'signcert' => '',
        ]);
        $pdf->font->insert($pdf->pon, 'helvetica', '', 10);

        foreach ($pageImages as $pageImage) {
            $page = $pdf->addPage(['format' => 'A4']);
            $imageId = $pdf->image->add($pageImage);
            $pdf->page->addContent(
                $pdf->image->getSetImage($imageId, 0, 0, $page['width'], $page['height'], $page['height']),
            );
        }

        $appearance = config('esign.signature_appearance');
        $this->addVisibleSignatureText($pdf, $page['pid'], $appearance, $signerName);
        $pdf->setSignatureAppearance(
            posx: $appearance['x'],
            posy: $appearance['y'],
            width: $appearance['width'],
            heigth: $appearance['height'],
            page: $page['pid'],
            name: 'Aadhaar eSign',
        );

        $prepared = $pdf->getExternalSignaturePreparation('sha256');
        file_put_contents($unsignedPath, $prepared['prepared_pdf']);

        foreach ($pageImages as $pageImage) {
            @unlink($pageImage);
        }

        @rmdir($tempDirectory);

        return [
            'byte_range' => $prepared['byte_range'],
            'hash' => $prepared['hash_hex'],
        ];
    }

    /**
     * @param  array{x: float, y: float, width: float, height: float}  $appearance
     */
    private function addVisibleSignatureText(EsignPdf $pdf, int $pageId, array $appearance, ?string $signerName): void
    {
        $pdf->addTextCellXY(
            txt: $this->visibleSignatureText($signerName),
            pid: $pageId,
            posx: $appearance['x'] + 17,
            posy: $appearance['y'],
            width: max(10, $appearance['width'] - 20),
            height: $appearance['height'],
            valign: 'T',
            halign: 'L',
            fill: true,
            drawcell: false,
            fit: 'F',
        );
    }

    private function visibleSignatureText(?string $signerName): string
    {
        $timestamp = now((string) config('esign.timestamp_timezone'));
        $signerName = preg_replace('/\s+/', ' ', trim((string) $signerName));
        $signedBy = 'Digitally Signed by:'.($signerName !== '' ? " {$signerName}" : '');

        return $signedBy.PHP_EOL
            .'Date: '.$timestamp->format('d-m-Y').PHP_EOL
            .$timestamp->format('h:i a');
    }

    /**
     * @return list<string>
     */
    private function sourceToImages(string $sourcePath, string $tempDirectory): array
    {
        $imagick = new Imagick;
        $imagick->setBackgroundColor(new ImagickPixel('transparent'));
        $imagick->setResolution(288, 288);
        $imagick->readImage($sourcePath);

        $pages = [];

        for ($i = 0; $i < $imagick->getNumberImages(); $i++) {
            $imagick->setIteratorIndex($i);
            $imagick->setImageFormat('jpeg');
            $imagick->stripImage();

            $pagePath = "{$tempDirectory}/page-{$i}.jpg";
            $imagick->writeImage($pagePath);
            $pages[] = $pagePath;
        }

        $imagick->destroy();

        return $pages;
    }

    private function signedRequestXml(string $txn, string $fileHash): string
    {
        $document = new DOMDocument;
        $timestamp = now((string) config('esign.timestamp_timezone'))->format('Y-m-d\TH:i:s');
        $xml = '<Esign AuthMode="'.$this->xmlAttribute(config('esign.auth_mode')).'" aspId="'.$this->xmlAttribute(config('esign.asp_id')).'" ekycId="" ekycIdType="A" responseSigType="'.$this->xmlAttribute(config('esign.response_signature_type')).'" responseUrl="'.$this->xmlAttribute(config('esign.response_url')).'" sc="y" ts="'.$timestamp.'" txn="'.$this->xmlAttribute($txn).'" ver="'.$this->xmlAttribute(config('esign.version')).'"><Docs><InputHash docInfo="'.$this->xmlAttribute($txn).'" hashAlgorithm="SHA256" id="1">'.$fileHash.'</InputHash></Docs></Esign>';

        $document->loadXML($xml);

        $signature = new XMLSecurityDSig;
        $signature->setCanonicalMethod(XMLSecurityDSig::C14N);
        $signature->addReference(
            $document,
            XMLSecurityDSig::SHA1,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature'],
            ['force_uri' => true],
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'private']);
        $key->passphrase = (string) config('esign.private_key_passphrase', '');
        $key->loadKey($this->privateKeyPath(), true);

        $signature->sign($key);
        $signature->appendSignature($document->documentElement);

        return str_replace('<?xml version="1.0"?>', '', $document->saveXML());
    }

    private function parseResponse(string $responseXml): SimpleXMLElement
    {
        $xml = simplexml_load_string($responseXml, SimpleXMLElement::class, LIBXML_NONET);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException('Failed to parse the eSign response XML.');
        }

        return $xml;
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function extractByteRange(string $pdf): array
    {
        if (! preg_match('/\/ByteRange\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $matches)) {
            throw new RuntimeException('The prepared PDF does not contain a signature byte range.');
        }

        return [
            (int) $matches[1],
            (int) $matches[2],
            (int) $matches[3],
            (int) $matches[4],
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function metadataByteRange(array $metadata, string $unsignedPdf): array
    {
        if (
            isset($metadata['byte_range'])
            && is_array($metadata['byte_range'])
            && count($metadata['byte_range']) === 4
        ) {
            return array_map('intval', array_values($metadata['byte_range']));
        }

        return $this->extractByteRange($unsignedPdf);
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $byteRange
     */
    private function assertMatchingByteRange(array $byteRange, int $expectedSignatureOffset): void
    {
        if ($byteRange[1] !== $expectedSignatureOffset) {
            throw new RuntimeException('The eSign response byte range does not match the prepared PDF.');
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseTxn(string $txn): array
    {
        $parts = explode('----', $txn, 2);

        if (count($parts) !== 2 || ! $this->isValidTransactionId($parts[0]) || ! ctype_digit($parts[1])) {
            throw new RuntimeException('The eSign response transaction id is invalid.');
        }

        return [$parts[0], (int) $parts[1]];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(string $transactionId): array
    {
        if (! $this->isValidTransactionId($transactionId)) {
            throw new RuntimeException('The eSign transaction id is invalid.');
        }

        $path = $this->transactionDirectory($transactionId).'/metadata.json';

        if (! $this->disk()->exists($path)) {
            throw new RuntimeException('No eSign transaction was found for the response.');
        }

        $metadata = json_decode($this->disk()->get($path), true);

        if (! is_array($metadata)) {
            throw new RuntimeException('The eSign transaction metadata is invalid.');
        }

        return $metadata;
    }

    private function transactionDirectory(string $transactionId): string
    {
        return trim((string) config('esign.storage_path'), '/').'/'.$transactionId;
    }

    private function isValidTransactionId(string $transactionId): bool
    {
        return preg_match('/^[A-Za-z0-9]{32}$/', $transactionId) === 1;
    }

    private function absolutePath(string $path): string
    {
        $adapter = $this->disk();

        if (! method_exists($adapter, 'path')) {
            throw new RuntimeException('The configured eSign disk must support local paths.');
        }

        return $adapter->path($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('esign.storage_disk'));
    }

    private function newPdf(): EsignPdf
    {
        $fontDirectory = $this->ensurePdfFontAvailable();

        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', $fontDirectory);
        }

        return new EsignPdf(
            unit: 'mm',
            isunicode: true,
            subsetfont: false,
            compress: true,
            mode: '',
            objEncrypt: null,
            fileOptions: [
                'allowedPaths' => [
                    storage_path('app/private'),
                    $fontDirectory,
                    base_path('vendor/tecnickcom'),
                ],
            ],
        );
    }

    private function ensurePdfFontAvailable(): string
    {
        $fontDirectory = storage_path('app/private/tc-lib-pdf-fonts');

        if (! is_dir($fontDirectory) && ! mkdir($fontDirectory, 0755, true) && ! is_dir($fontDirectory)) {
            throw new RuntimeException("Unable to create tc-lib-pdf font directory: {$fontDirectory}");
        }

        if (file_exists($fontDirectory.'/helvetica.json')) {
            return $fontDirectory;
        }

        $sourceFont = base_path('vendor/tecnickcom/tc-font-mirror/freefont/FreeSans.ttf');
        $helveticaFont = $fontDirectory.'/helvetica.ttf';

        if (! file_exists($sourceFont)) {
            throw new RuntimeException('tc-lib-pdf font source is missing. Run composer install to restore tecnickcom/tc-font-mirror.');
        }

        if (! file_exists($helveticaFont) && ! copy($sourceFont, $helveticaFont)) {
            throw new RuntimeException("Unable to copy tc-lib-pdf font to {$helveticaFont}");
        }

        new FontImport(
            file: $helveticaFont,
            output_path: $fontDirectory.DIRECTORY_SEPARATOR,
            type: 'TrueTypeUnicode',
            encoding: '',
            flags: 32,
            platform_id: 3,
            encoding_id: 1,
            linked: false,
            fileHelper: new TecnickFile(allowedPaths: [
                dirname($sourceFont),
                $fontDirectory,
            ]),
        );

        return $fontDirectory;
    }

    private function ensurePrivateKeyExists(): void
    {
        $privateKey = $this->privateKeyPath();

        if ($privateKey === '' || ! file_exists($privateKey)) {
            throw new RuntimeException("eSign private key was not found at {$privateKey}. Add it under the root keys folder or update ESIGN_PRIVATE_KEY.");
        }
    }

    private function privateKeyPath(): string
    {
        return $this->rootRelativePath((string) config('esign.private_key'));
    }

    private function rootRelativePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
    }

    private function xmlAttribute(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
