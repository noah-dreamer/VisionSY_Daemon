<?php
namespace App\Services\Management;

class RuntimeStatus
{
    public function processes(): array
    {
        $socket = @stream_socket_client('unix:///run/visionsy/supervisor.sock', $errno, $error, 1);
        if (! $socket) {
            return [];
        }
        stream_set_timeout($socket, 2);
        $body = '<?xml version="1.0"?><methodCall><methodName>supervisor.getAllProcessInfo</methodName><params/></methodCall>';
        fwrite($socket, "POST /RPC2 HTTP/1.0\r\nHost: localhost\r\nContent-Type: text/xml\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
        $response = stream_get_contents($socket);
        fclose($socket);
        $xml = @simplexml_load_string(explode("\r\n\r\n", $response, 2)[1] ?? '');
        $result = [];
        foreach ($xml?->params->param->value->array->data->value ?? [] as $value) {
            $fields = [];
            foreach ($value->struct->member as $member) {
                $fields[(string) $member->name] = (string) ($member->value->string ?? $member->value->int);
            }
            if (isset($fields['name'])) {
                $result[] = ['name' => $fields['name'], 'state' => $fields['statename'] ?? 'UNKNOWN', 'pid' => (int) ($fields['pid'] ?? 0), 'started_at' => (int) ($fields['start'] ?? 0)];
            }
        }
        return $result;
    }
}
