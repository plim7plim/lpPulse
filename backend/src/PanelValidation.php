<?php
declare(strict_types=1);
namespace Pulse;

final class PanelError extends \RuntimeException {
    public int $httpStatus;
    public string $errorCode;
    public function __construct(string $code, string $message, int $status = 422) {
        parent::__construct($message); $this->errorCode = $code; $this->httpStatus = $status;
    }
}
final class PanelValidation {
    public static function text(array $data, string $key, int $max, bool $required = true): string {
        $value = $data[$key] ?? '';
        if (!is_string($value) || !preg_match('//u', $value)) throw new PanelError('invalid_field', 'Campo inválido: '.$key);
        $value = trim($value);
        if (($required && $value === '') || strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) throw new PanelError('invalid_field', 'Verifique o campo: '.$key);
        return $value;
    }
    public static function email(array $data, string $key, bool $required = true): string {
        $value = strtolower(self::text($data, $key, 254, $required));
        if ($value !== '' && (!filter_var($value, FILTER_VALIDATE_EMAIL) || preg_match('/[^\x20-\x7E]/', $value))) throw new PanelError('invalid_email', 'E-mail inválido.');
        return $value;
    }
    public static function choice(array $data, string $key, array $allowed): string {
        $value = self::text($data, $key, 64);
        if (!in_array($value, $allowed, true)) throw new PanelError('invalid_choice', 'Opção inválida: '.$key);
        return $value;
    }
    public static function id($value): string {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string)$value)) throw new PanelError('invalid_id', 'Identificador inválido.');
        return (string)$value;
    }
    public static function amount($value): string {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,8})\.[0-9]{2}$/D', $value) || $value === '0.00') throw new PanelError('invalid_amount', 'Informe valor positivo como string decimal: 100.00.');
        return $value;
    }
    public static function details($value): string {
        if (!is_array($value) || $value === [] || count($value) > 30) throw new PanelError('invalid_details', 'Detalhes obrigatórios.');
        foreach ($value as $key => $item) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) || (!is_string($item) && !is_int($item) && !is_bool($item))) throw new PanelError('invalid_details', 'Detalhes devem conter campos simples.');
            if (is_string($item)) self::text([$key => $item], $key, 2000, false);
        }
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($json) > 16000) throw new PanelError('invalid_details', 'Detalhes muito grandes.');
        return $json;
    }
    public static function noAttachments(array $data): void {
        if (!empty($data['attachments']) || !empty($data['files'])) throw new PanelError('uploads_unavailable', 'Anexos ainda não estão conectados ao servidor.');
    }
}
