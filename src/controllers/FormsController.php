<?php
namespace verbb\formie\controllers;

use Craft;
use craft\base\Field;
use craft\errors\MissingComponentException;
use craft\errors\SiteNotFoundException;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\validators\HandleValidator;
use craft\web\Controller;

use ReflectionException;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

use yii\base\Exception;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

use ReflectionClass;
use Throwable;

use verbb\formie\Formie;
use verbb\formie\elements\Form;
use verbb\formie\models\FormSettings;
use verbb\formie\fields\formfields\SingleLineText;

class FormsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function init()
    {
        $this->requirePermission('formie-manageForms');

        parent::init();
    }

    /**
     * Shows all the forms in a list.
     *
     * @return Response|null
     */
    public function actionIndex()
    {
        return $this->renderTemplate('formie/forms/index', []);
    }

    /**
     * Creates a new form with a pretty interface.
     *
     * @param Form|null $form
     * @return Response|null
     */
    public function actionNew(Form $form = null)
    {
        $formHandles = ArrayHelper::getColumn(Form::find()->all(), 'handle');
        $stencilArray = Formie::$plugin->getStencils()->getStencilArray();

        $variables = compact('formHandles', 'form', 'stencilArray');
        if (!$variables['form']) {
            $variables['form'] = new Form();
        }

        $variables['reservedHandles'] = Formie::$plugin->getFields()->getReservedHandles();

        return $this->renderTemplate('formie/forms/_new', $variables);
    }

    /**
     * Renders the main form builder interface.
     *
     * @param int|null $formId
     * @param string|null $siteHandle
     * @param Form|null $form
     * @return Response|null
     * @throws Throwable
     */
    public function actionEdit(int $formId = null, string $siteHandle = null, Form $form = null): Response
    {
        $variables = compact('formId', 'form');

        if ($siteHandle !== null) {
            $variables['site'] = Craft::$app->getSites()->getSiteByHandle($siteHandle);

            if (!$variables['site']) {
                throw new NotFoundHttpException('Invalid site handle: ' . $siteHandle);
            }
        }

        $this->_prepareVariableArray($variables);

        if (!empty($variables['form']->id)) {
            $variables['title'] = $variables['form']->title;
        } else {
            $variables['title'] = Craft::t('formie', 'Create a new form');
        }

        // Can't just use the entry's getCpEditUrl() because that might include the site handle when we don't want it
        $variables['baseCpEditUrl'] = 'formie/forms/edit/{id}';

        // Set the "Continue Editing" URL
        $variables['continueEditingUrl'] = $variables['baseCpEditUrl'] .
            (Craft::$app->getIsMultiSite() && Craft::$app->getSites()->currentSite->id !== $variables['site']->id ? '/' . $variables['site']->handle : '');

        return $this->renderTemplate('formie/forms/_edit', $variables);
    }

    /**
     * Saves a form.
     *
     * @return Response|null
     * @throws Throwable
     */
    public function actionSave()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $this->requirePermission('formie-editForms');

        $form = Formie::$plugin->getForms()->buildFormFromPost();

        if (!Formie::$plugin->getForms()->saveForm($form)) {
            if ($request->getAcceptsJson()) {
                $notifications = $form->getNotifications();
                $notificationsConfig = Formie::$plugin->getNotifications()->getNotificationsConfig($notifications);

                return $this->asJson([
                    'id' => $form->id,
                    'config' => $form->getFormConfig(),
                    'notifications' => $notificationsConfig,
                    'errors' => $form->getErrors(),
                    'success' => !$form->hasErrors(),
                    'fieldLayoutId' => $form->fieldLayoutId,
                ]);
            }

            Craft::$app->getSession()->setError(Craft::t('formie', 'Couldn’t save form.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form,
                'errors' => $form->getErrors(),
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'id' => $form->id,
                'config' => $form->getFormConfig(),
                'errors' => $form->getErrors(),
                'success' => !$form->hasErrors(),
                'fieldLayoutId' => $form->fieldLayoutId,
            ]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('formie', 'Form saved.'));

        return $this->redirectToPostedUrl($form);
    }

    /**
     * Returns tabs and fields HTML when the form template is switched.
     *
     * @return Response
     * @throws Throwable
     */
    public function actionSwitchTemplate()
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $form = Formie::$plugin->getForms()->buildFormFromPost();

        $variables = [];
        $variables['form'] = $form;
        $variables['templateId'] = $form->templateId;

        $this->_prepareVariableArray($variables);

        $view = Craft::$app->getView();

        $fieldsHtml = [];

        if ($fieldLayout = $form->getFieldLayout()) {
            foreach ($fieldLayout->getTabs() as $tab) {
                $tabSlug = StringHelper::toKebabCase($tab->name);

                $fieldsHtml[] = [
                    'id' => "tab-form-fields-$tabSlug",
                    'html' => $view->renderTemplate('_includes/fields', [
                        'element' => $form,
                        'fields' => $tab->getFields(),
                    ]),
                ];
            }
        }

        $tabsHtml = $view->renderTemplate('_includes/tabs', $variables);
        $positionsHtml = $view->renderTemplate('formie/forms/_panes/_positions', $variables);

        $headHtml = $view->getHeadHtml();
        $bodyHtml = $view->getBodyHtml();

        return $this->asJson(compact(
            'tabsHtml',
            'fieldsHtml',
            'positionsHtml',
            'headHtml',
            'bodyHtml'
        ));
    }

    /**
     * @return Response|null
     * @throws Throwable
     */
    public function actionDeleteForm()
    {
        $this->requirePostRequest();

        $this->requirePermission('formie-editForms');

        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        $form = Formie::$plugin->getForms()->getFormById($formId);

        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }

        if (!Craft::$app->getElements()->deleteElement($form)) {
            if ($request->getAcceptsJson()) {
                return $this->asJson(['success' => false]);
            }

            Craft::$app->getSession()->setError(Craft::t('app', 'Couldn’t delete form.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'form' => $form,
            ]);

            return null;
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('app', 'Form deleted.'));

        if ($request->getAcceptsJson()) {
            $url = Craft::$app->getRequest()->getValidatedBodyParam('redirect');
            $url = Craft::$app->getView()->renderObjectTemplate($url, $form);

            return $this->asJson([
                'success' => false,
                'redirect' => UrlHelper::url($url),
            ]);
        }

        return $this->redirectToPostedUrl($form);
    }


    // Private Methods
    // =========================================================================

    /**
     * Prepares the variable array for rendering the form builder.
     *
     * @param array $variables
     * @throws Throwable
     */
    private function _prepareVariableArray(&$variables)
    {
        // Locale related checks
        if (Craft::$app->getIsMultiSite()) {
            // Only use the sites that the user has access to
            $variables['siteIds'] = Craft::$app->getSites()->getEditableSiteIds();
        } else {
            $variables['siteIds'] = [Craft::$app->getSites()->getPrimarySite()->id];
        }

        if (!$variables['siteIds']) {
            throw new ForbiddenHttpException('User not permitted to edit content in any sites supported by this form');
        }

        if (empty($variables['site'])) {
            $site = $variables['site'] = Craft::$app->getSites()->currentSite;

            if (!in_array($variables['site']->id, $variables['siteIds'], false)) {
                $site = $variables['site'] = Craft::$app->getSites()->getSiteById($variables['siteIds'][0]);
            }
        } else {
            // Make sure they were requesting a valid site
            /** @var Site $site */
            $site = $variables['site'];

            if (!in_array($site->id, $variables['siteIds'], false)) {
                throw new ForbiddenHttpException('User not permitted to edit content in this site');
            }
        }

        if (empty($variables['form'])) {
            if (!empty($variables['formId'])) {
                $variables['form'] = Formie::$plugin->getForms()->getFormById($variables['formId'], $site->id);

                if (!$variables['form']) {
                    throw new Exception('Missing form data.');
                }
            } else {
                $variables['form'] = new Form();

                if (!empty($variables['site'])) {
                    /** @var Site $site */
                    $site = $variables['site'];

                    $variables['form']->siteId = $site->id;
                }
            }
        }

        /** @var Form $form */
        $form = $variables['form'];

        // Enable locales
        if ($form->id) {
            $variables['enabledSiteIds'] = Craft::$app->getElements()->getEnabledSiteIdsForElement($form->id);
        } else {
            $variables['enabledSiteIds'] = [];

            foreach (Craft::$app->getSites()->getEditableSiteIds() as $site) {
                $variables['enabledSiteIds'][] = $site;
            }
        }

        // When there's only a single tab, it looks like Craft switches it to a null value.
        // Pretty bizarre default behaviour!
        $variables['tabs'] = $variables['formTabs'] = Formie::$plugin->getForms()->buildTabs($form);
        $variables['notificationsSchema'] = Formie::$plugin->getNotifications()->getNotificationsSchema();

        $notifications = $form->getNotifications();
        $notificationsConfig = Formie::$plugin->getNotifications()->getNotificationsConfig($notifications);

        $variables['formConfig'] = $form->getFormConfig();
        $variables['notifications'] = $notificationsConfig;
        $variables['fields'] = Formie::$plugin->getFields()->getRegisteredFieldGroups();
        $variables['existingFields'] = Formie::$plugin->getFields()->getExistingFields($form);
        $variables['emailTemplates'] = Formie::$plugin->getEmailTemplates()->getAllTemplates();
        $variables['reservedHandles'] = Formie::$plugin->getFields()->getReservedHandles();
        $variables['integrations'] = Formie::$plugin->getintegrations()->getAllIntegrations();
        $variables['formHandles'] = ArrayHelper::getColumn(Form::find()->id('not ' . $form->id)->all(), 'handle');
    }
}
