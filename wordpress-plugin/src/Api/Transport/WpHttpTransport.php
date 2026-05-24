<?php

declare(strict_types=1);

namespace OrcaDam\Api\Transport;

/**
 * WordPress-flavoured Transport built on wp_remote_request().
 */
final class WpHttpTransport implements Transport
{
    public function request(string $method, string $url, array $query = [], ?array $body = null, array $headers = []): TransportResponse
    {
        if ($query !== []) {
            $url = add_query_arg(array_map(
                static fn ($v) => is_array($v) ? implode(',', $v) : $v,
                $query,
            ), $url);
        }

        $args = [
            'method'  => $method,
            'headers' => array_merge([
                'Accept'     => 'application/json',
                'User-Agent' => 'OrcaDamPicker/' . ORCA_DAM_PICKER_VERSION . '; ' . home_url('/'),
            ], $headers),
            'timeout' => 15,
        ];

        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new TransportResponse(0, ['message' => $response->get_error_message()], '');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);

        return new TransportResponse(
            $status,
            is_array($decoded) ? $decoded : [],
            $raw,
        );
    }
}
