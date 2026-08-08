<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordLinkNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $resetUrl)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = data_get($notifiable, 'first_name') ?: 'пользователь';

        return (new MailMessage)
            ->subject('Сброс пароля — Новая Я')
            ->greeting('Здравствуйте, '.$firstName.'!')
            ->line('Мы получили запрос на сброс пароля вашего личного кабинета.')
            ->action('Создать новый пароль', $this->resetUrl)
            ->line('Ссылка действует 60 минут. Если вы не запрашивали сброс, просто проигнорируйте письмо.');
    }
}
