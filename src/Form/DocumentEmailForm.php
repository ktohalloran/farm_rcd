<?php

declare(strict_types=1);

namespace Drupal\farm_rcd\Form;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\plan\Entity\PlanInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Document email form.
 */
class DocumentEmailForm extends PlanningWorkflowFormBase {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected MailerInterface $mailer,
  ) {
    parent::__construct($this->entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_rcd_document_email_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PlanInterface $plan = NULL) {
    $form = parent::buildForm($form, $form_state, $plan);

    $form['email'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Document'),
      '#description' => $this->t('Use this form to send documents via email to yourself or a stakeholder.'),
    ];

    // Require that files are attached to the plan first.
    if ($this->plan->get('file')->isEmpty()) {
      $form['email']['#markup'] = $this->t('One or more documents must be uploaded before they can be sent via email.');
      return $form;
    }

    // Email address.
    $email_address = '';
    if (!is_null($this->intake) && !$this->intake->get('intake_stakeholder_email')->isEmpty()) {
      $email_address = $this->intake->get('intake_stakeholder_email')->value;
    }
    $form['email']['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#description' => $this->t('Use this to specify an email address to receive one or more documents, if desired.'),
      '#default_value' => $email_address,
      '#required' => TRUE,
    ];

    // Checkboxes to select the documents to include as attachments.
    $doc_options = [];
    foreach ($this->plan->get('file')->referencedEntities() as $file) {
      $doc_options[$file->id()] = $file->label();
    }
    $form['email']['include_docs'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Documents to email'),
      '#description' => $this->t('Which documents should be included in the email?'),
      '#options' => $doc_options,
      '#required' => TRUE,
    ];

    // Email documents button.
    $form['email']['actions'] = [
      '#type' => 'actions',
      '#weight' => 1000,
    ];
    $form['email']['actions']['email'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send email'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email_address = $form_state->getValue(['email', 'email']);
    $document_fids = $form_state->getValue(['email', 'include_docs']);
    $mail_config = $this->configFactory()->get('farm_rcd.mail');
    $mail_key = 'rcp_document';

    // Filter out empty values for files that were not selected.
    $selected_fids = [];
    foreach ($document_fids as $fid) {
      if ($fid) {
        $selected_fids[] = $fid;
      }
    }

    // Construct the email.
    $email = (new Email())
      ->from($this->configFactory()->get('system.site')->get('mail'))
      ->to($email_address)
      ->subject($mail_config->get($mail_key . '.subject'))
      ->text($mail_config->get($mail_key . '.body'));

    // Attach documents and save filenames.
    $filenames = [];
    if (count($selected_fids)) {
      foreach ($selected_fids as $fid) {
        $file_entity = File::load($fid);

        $file_name = $file_entity->getFilename();
        $filenames[] = $file_name;

        $email->attachFromPath(
          $file_entity->getFileUri(),
          $file_name,
          $file_entity->getMimeType()
        );
      }
    }

    // Send the email.
    $this->mailer->send($email);

    // Construct success message.
    $filenames_str = implode(', ', $filenames);
    $success_message = 'Document(s) emailed to %email: %filenames';

    // Update plan's revision log message.
    $this->plan->setRevisionLogMessage($this->t($success_message, ['%email' => $email_address, '%filenames' => $filenames_str]));
    $this->plan->save();

    // Display message.
    $this->messenger()->addMessage($this->t($success_message, ['%email' => $email_address, '%filenames' => $filenames_str]));
  }

}
