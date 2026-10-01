<?php

namespace App\Helpers;

class Validator
{
    private array $errors = [];

    public function required(array $data, array $fields): self
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $this->errors[$field][] = "O campo {$field} é obrigatório.";
            }
        }
        return $this;
    }

    public function email(array $data, string $field): self
    {
        if (isset($data[$field]) && !filter_var($data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = "O campo {$field} deve ser um e-mail válido.";
        }
        return $this;
    }

    public function minLength(array $data, string $field, int $min): self
    {
        if (isset($data[$field]) && is_string($data[$field]) && strlen(trim($data[$field])) < $min) {
            $this->errors[$field][] = "O campo {$field} deve ter no mínimo {$min} caracteres.";
        }
        return $this;
    }

    public function maxLength(array $data, string $field, int $max): self
    {
        if (isset($data[$field]) && is_string($data[$field]) && strlen(trim($data[$field])) > $max) {
            $this->errors[$field][] = "O campo {$field} deve ter no máximo {$max} caracteres.";
        }
        return $this;
    }

    public function inList(array $data, string $field, array $list): self
    {
        if (isset($data[$field]) && !in_array($data[$field], $list)) {
            $this->errors[$field][] = "O campo {$field} deve ser um dos valores: " . implode(', ', $list) . ".";
        }
        return $this;
    }

    public function numeric(array $data, string $field): self
    {
        if (isset($data[$field]) && !is_numeric($data[$field])) {
            $this->errors[$field][] = "O campo {$field} deve ser numérico.";
        }
        return $this;
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function get(string $field, array $data, mixed $default = null): mixed
    {
        return $data[$field] ?? $default;
    }
}
