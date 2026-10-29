<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Added to the Symfony Demo by Tracewick's reference app: one request that waits on both the
 * database and an external HTTP service, so that wall-time profiles show both kinds of waiting.
 */
final class ReferenceController extends AbstractController
{
    public function external(PostRepository $posts): JsonResponse
    {
        $baseUrl = getenv('EXTERNAL_HTTP_URL') ?: 'http://toxiproxy:18080';

        $curl = curl_init(rtrim($baseUrl, '/') . '/get');
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return new JsonResponse(
            [
                'posts' => $posts->count([]),
                'upstream_status' => $status,
                'upstream_bytes' => \is_string($body) ? \strlen($body) : 0,
            ],
            $status === 200 ? 200 : 502,
        );
    }
}
