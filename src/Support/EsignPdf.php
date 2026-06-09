<?php

namespace Mozhuilungdsuo\LaravelCdacEHastakshar\Support;

use Com\Tecnick\Pdf\Tcpdf;

class EsignPdf extends Tcpdf
{
    /**
     * C-DAC eSign PKCS#7 payloads can be larger than tc-lib-pdf's default.
     */
    protected const SIGMAXLEN = 65536;
}
