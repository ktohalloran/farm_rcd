<?php

declare(strict_types=1);

namespace Drupal\farm_rcd\Hook;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Utility\Token;

/**
 * Mail hook implementations for farm_rcd.
 */
class MailHooks {

  use AutowireTrait;

  public function __construct(
    protected Token $token,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_mail().
   */
  #[Hook('mail')]
  public function mail($key, &$message, $params): void {
    $mail_config = $this->configFactory->get('farm_rcd.mail');
    $message['subject'] .= PlainTextOutput::renderFromHtml($this->token->replace($mail_config->get($key . '.subject'), $params));
    $message['body'][] = $this->token->replace($mail_config->get($key . '.body'), $params);
  }

}
