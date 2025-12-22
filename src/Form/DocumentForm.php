<?php

declare(strict_types=1);

namespace Drupal\farm_rcd\Form;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\farm_rcd\DocumentGeneratorInterface;
use Drupal\file\FileInterface;
use Drupal\plan\Entity\PlanInterface;

/**
 * Document form.
 */
class DocumentForm extends PlanningWorkflowFormBase {

  use AutowireTrait;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected DocumentGeneratorInterface $documentGenerator,
    protected FileSystemInterface $fileSystem,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected MailManagerInterface $mailManager,
  ) {
    parent::__construct($this->entityTypeManager);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'farm_rcd_document_form';
  }

  /**
   * Utility for getting RCP documents.
   */
  private function getPlanDocuments() {
    $filenames = [];
    $files = $this->plan->get('file')->referencedEntities();
    foreach ($files as $file) {
      $filenames[] = $file->label();
    }
    return $filenames;
}

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?PlanInterface $plan = NULL) {
    $form = parent::buildForm($form, $form_state, $plan);

    $form['document'] = [
      '#type' => 'details',
      '#title' => $this->t('Prepare Document'),
      '#description' => $this->t('Use this form to generate and upload documents for this plan. This will take all of the information in the associated records above and use it to generate a draft document from a template. This document can then be downloaded, modified, and re-uploaded for storage purposes.'),
    ];

    // Open if the status is "planning" and there are conservation practice
    // plans associated with the plan, but no files are attached.
    $form['document']['#open'] = $this->plan->get('status')->value == 'planning' && !empty($this->practicePlans) && $this->plan->get('file')->isEmpty();

    // Generate document button.
    $form['document']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate document from template'),
      '#submit' => [[$this, 'submitGenerate']],
    ];

    // Upload finished document.
    $form['document']['upload'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload document'),
      '#description' => $this->t('Use this to upload the finished document.'),
      '#upload_validators' => [
        'FileExtension' => [
          'extensions' => 'docx odt pdf',
        ],
      ],
    ];

    $form['document']['email'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email address'),
      '#description' => $this->t('Use this to specify an email address to receive one or more documents, if desired.'),
      '#default_value' => $this->intake->get('intake_stakeholder_email')->value ?? '',
    ];

    $form['document']['include_docs'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Documents to email'),
      '#description' => $this->t('Which documents should be included in the email?'),
      '#options' => $this->getPlanDocuments(),
    ];

    // Save documents button.
    $form['document']['actions'] = [
      '#type' => 'actions',
      '#weight' => 1000,
    ];

    $form['document']['actions']['email'] = [
      '#type' => 'submit',
      '#value' => $this->t('Email document'),
      '#submit' => [[$this, 'submitEmail']],
    ];

    $form['document']['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save documents'),
    ];

    return $form;
  }

  /**
   * Submit function for generating a document from template.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitGenerate(array &$form, FormStateInterface $form_state) {
    try {

      // Get the document template path.
      $template_path = $this->moduleHandler->getModule('farm_rcd')->getPath() . '/templates/rcp-template.docx';

      // Generate a filename.
      $filename = strtolower(trim(preg_replace('#\W+#', '-', $this->plan->label()), '-')) . '.docx';

      // Add the plan to the document generation context.
      $context = [
        'plan' => $this->plan,
      ];

      // Generate the report.
      $file = $this->documentGenerator->generate($template_path, $filename, $context);

      // Show a link to the file.
      $url = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      $this->messenger()->addMessage($this->t('Document created: <a href=":url">%filename</a>', [
        ':url' => $url,
        '%filename' => $file->label(),
      ]));
    }
    catch (\Exception $e) {
      $this->messenger()->addWarning($this->t('Document generation failed. @error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * Submit function for generating and sending an email with chosen documents.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitEmail (array &$form, FormStateInterface $form_state) {
    $email_address = $form_state->getValue(['document', 'email']);
    $this->mailManager->mail('farm_rcd', 'practice_document_stakeholder', $email_address, 'en');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    // Only allow uploading documents if the plan status is planning.
    $status = $this->plan->get('status')->value;
    if (!empty($form_state->getValue(['document', 'upload'])) && $status != 'planning') {
      $form_state->setError($form['document']['upload'], $this->t('Documents can only be uploaded to plans that are in the planning stage. This plan has been marked as @status.', ['@status' => $status]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    // Process uploaded file.
    if (!empty($form_state->getValue(['document', 'upload']))) {

      // Prepare the private://rcp/[date] directory.
      $directory = 'private://rcp/' . date('Y-m-d');
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);

      // Save the uploaded file.
      $validators = ['FileExtension' => ['extensions' => 'docx odt pdf']];
      $file = file_save_upload('document', $validators, $directory, 0, FileExists::Rename);

      // Show an error if upload failed.
      if (!($file instanceof FileInterface)) {
        $this->messenger()->addError('The file could not be uploaded.');
        return;
      }

      // Attach the file to the plan and save a revision log message.
      $this->plan->get('file')->appendItem($file);
      $this->plan->setRevisionLogMessage((string) new FormattableMarkup('Document uploaded: <a href=":uri">' . $file->label() . '</a>.', [':uri' => $this->fileUrlGenerator->generateString($file->getFileUri()), '@label' => $file->label()]));
      $this->plan->save();

      // Show a message.
      $this->messenger()->addMessage($this->t('Document uploaded.'));
    }

    // Otherwise, show a warning.
    else {
      $this->messenger()->addWarning($this->t('No document uploaded.'));
    }
  }

}
