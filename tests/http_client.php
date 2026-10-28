<?php
declare(strict_types=1);

// Petit client de recette, sans dépendance ni suivi automatique des redirections.
function browser_request(int $port, string $path, array &$cookies, ?array $post = null): array
{
    $socket = @stream_socket_client("tcp://127.0.0.1:$port", $code, $message, 5);
    if (!$socket) { throw new RuntimeException('Serveur de recette inaccessible.'); }
    stream_set_timeout($socket, 15);
    $body = $post === null ? '' : http_build_query($post);
    $request = ($post === null ? 'GET' : 'POST') . " $path HTTP/1.1\r\nHost: 127.0.0.1:$port\r\nConnection: close\r\n";
    if ($cookies) { $request .= 'Cookie: ' . http_build_query($cookies, '', '; ') . "\r\n"; }
    if ($post !== null) { $request .= "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n"; }
    $request .= "\r\n" . $body;
    try {
        for ($offset = 0; $offset < strlen($request); $offset += $written) {
            $written = fwrite($socket, substr($request, $offset));
            if (!$written) { throw new RuntimeException('Envoi HTTP incomplet.'); }
        }
        $raw = stream_get_contents($socket);
        $metadata = stream_get_meta_data($socket);
    } finally { fclose($socket); }
    if ($raw === false || $metadata['timed_out'] || !str_contains($raw, "\r\n\r\n")) { throw new RuntimeException('Réponse HTTP incomplète.'); }
    [$headers, $html] = explode("\r\n\r\n", $raw, 2);
    if (!preg_match('#^HTTP/1\.[01] ([0-9]+)#', $headers, $status)) { throw new RuntimeException('Statut HTTP absent.'); }
    preg_match_all('/^Set-Cookie: anrt_session=([^;]*)/mi', $headers, $matches);
    foreach ($matches[1] as $cookie) {
        if ($cookie === '' || $cookie === 'deleted') { unset($cookies['anrt_session']); }
        else { $cookies['anrt_session'] = $cookie; }
    }
    return [(int) $status[1], $headers, $html];
}

function browser_token(string $html, string $name = 'csrf_token'): string
{
    if (!preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]+)"/', $html, $match)) { throw new RuntimeException('Champ de recette absent : ' . $name); }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}
