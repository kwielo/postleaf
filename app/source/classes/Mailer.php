<?php

namespace Postleaf;

use Postleaf\Mailer\Mail\Mail,
    Postleaf\Mailer\MailerException,
    Postleaf\Mailer\Bridge\MailerInterface;

class Mailer extends Postleaf
{
    /**
     * @var array
     */
    static private $mailers = [
        'default' => [
            'name' => 'php mailer',
            'class' => 'Postleaf\Mailer\Bridge\MailMailer'
        ]
    ];

    /**
     * @return array
     */
    static public function getMailers() {
        return self::$mailers;
    }

    /**
     * @param Mail $mail
     *
     * @return bool
     * @throws MailerException
     */
    static public function sendEmail(Mail $mail)
    {
        $mailerClass = self::getMailerClass(Setting::get('mailer'));
        /** @var MailerInterface $mailer */
        $mailer = new $mailerClass();

        if (!($mailer instanceof MailerInterface)) {
            throw new MailerException("Mailer {$mailerClass} must implement MailerInterface");
        }

        return $mailer->send($mail);
    }

    /**
     * @param string $mailerName
     *
     * @throws MailerException
     */
    static private function getMailerClass($mailerName) {
        foreach(self::$mailers as $name => $mailer) {
            if ($mailerName === $name) {
                return $mailer['class'];
            }
        }
        throw new MailerException("Given mailer: '$mailerName' doesn't exist");
    }

}
