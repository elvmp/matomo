<?php

use Piwik\DI;
use Piwik\Plugins\TrackingAssistant\Service\Diagnostic\AssertionEvaluator;
use Piwik\Plugins\TrackingAssistant\Service\Diagnostic\DiagnosisEngine;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\CanonicalJson;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\ProposalService;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\ProposalValidator;
use Piwik\Plugins\TrackingAssistant\Service\Proposal\RollbackManager;
use Piwik\Plugins\TrackingAssistant\Service\Runner\Protocol;
use Piwik\Plugins\TrackingAssistant\Service\Runner\RunnerClient;
use Piwik\Plugins\TrackingAssistant\Service\Security\RedactionService;
use Piwik\Plugins\TrackingAssistant\Service\Security\UrlPolicy;
use Piwik\Plugins\TrackingAssistant\Service\TagManager\TagManagerAdapter;
use Piwik\Plugins\TrackingAssistant\Service\Validation\BeforeAfterComparator;
use Piwik\Plugins\TrackingAssistant\Service\Validation\ValidationFinalizer;

return [
    Protocol::class => DI::autowire(),
    RunnerClient::class => DI::autowire(),
    RedactionService::class => DI::autowire(),
    UrlPolicy::class => DI::autowire(),
    CanonicalJson::class => DI::autowire(),
    TagManagerAdapter::class => DI::autowire(),
    AssertionEvaluator::class => DI::autowire(),
    DiagnosisEngine::class => DI::autowire(),
    ProposalValidator::class => DI::autowire(),
    ProposalService::class => DI::autowire(),
    RollbackManager::class => DI::autowire(),
    BeforeAfterComparator::class => DI::autowire(),
    ValidationFinalizer::class => DI::autowire(),
];
