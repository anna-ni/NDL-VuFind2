<?php
/**
 * Online payment controller trait.
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2015-2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Controller
 * @author   Leszek Manicki <leszek.z.manicki@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:controllers Wiki
 */
namespace Finna\Controller;

use Zend\Console\Console;
use Zend\Session\Container as SessionContainer;

/**
 * Online payment controller trait.
 *
 * @category VuFind
 * @package  Controller
 * @author   Leszek Manicki <leszek.z.manicki@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:plugins:controllers Wiki
 */
trait OnlinePaymentControllerTrait
{
    use \VuFind\Log\LoggerAwareTrait;

    /**
     * Checks if the given list of fines is identical to the listing
     * preserved in the session variable.
     *
     * @param object $patron Patron.
     * @param array  $fines  Listing of fines.
     *
     * @return boolean updated
     */
    protected function checkIfFinesUpdated($patron, $fines)
    {
        $session = $this->getOnlinePaymentSession();
        return !$session
            || ($session->sessionId !== $this->generateFingerprint($patron))
            || ($session->fines !== $this->generateFingerprint($fines))
        ;
    }

    /**
     * Utility function for calculating a fingerprint for a object.
     *
     * @param object $data Object
     *
     * @return string fingerprint
     */
    protected function generateFingerprint($data)
    {
        return md5(json_encode($data));
    }

    /**
     * Return online payment handler.
     *
     * @param string $driver Patron MultiBackend ILS source
     *
     * @return mixed \Finna\OnlinePayment\BaseHandler or false on failure.
     */
    protected function getOnlinePaymentHandler($driver)
    {
        $onlinePayment = $this->serviceLocator->get('Finna\OnlinePayment');
        if (!$onlinePayment->isEnabled($driver)) {
            return false;
        }

        try {
            return $onlinePayment->getHandler($driver);
        } catch (\Exception $e) {
            $this->handleError(
                "Error retrieving online payment handler for driver $driver"
                . ' (' . $e->getMessage() . ')'
            );
            return false;
        }
    }

    /**
     * Get session for storing payment data.
     *
     * @return SessionContainer
     */
    protected function getOnlinePaymentSession()
    {
        return new SessionContainer(
            'OnlinePayment',
            $this->serviceLocator->get('VuFind\SessionManager')
        );
    }

    /**
     * Support for handling online payments.
     *
     * @param object    $patron Patron.
     * @param array     $fines  Listing of fines.
     * @param ViewModel $view   View
     *
     * @return void
     */
    protected function handleOnlinePayment($patron, $fines, $view)
    {
        $view->onlinePaymentEnabled = false;
        $session = $this->getOnlinePaymentSession();

        $catalog = $this->getILS();

        // Check if online payment configuration exists for the ILS driver
        $paymentConfig = $catalog->getConfig('onlinePayment', $patron);
        if (empty($paymentConfig)) {
            return;
        }

        // Check if payment handler is configured in datasources.ini
        $onlinePayment = $this->serviceLocator->get('Finna\OnlinePayment');
        if (!$onlinePayment->isEnabled($patron['source'])) {
            return;
        }

        // Check if online payment is enabled for the ILS driver
        if (!$catalog->checkFunction('markFeesAsPaid', $patron)) {
            return;
        }

        // Check that mandatory settings exist
        if (!isset($paymentConfig['currency'])) {
            $this->handleError(
                "Mandatory setting 'currency' missing from ILS driver for"
                . " '{$patron['source']}'"
            );
            return false;
        }

        $payableOnline = $catalog->getOnlinePayableAmount($patron);

        // Check if there is a payment in progress
        // or if the user has unregistered payments
        $transactionMaxDuration
            = isset($paymentConfig['transactionMaxDuration'])
            ? $paymentConfig['transactionMaxDuration']
            : 30
        ;

        $tr = $this->getTable('transaction');
        $paymentPermittedForUser = $tr->isPaymentPermitted(
            $patron['cat_username'], $transactionMaxDuration
        );

        if (!$paymentHandler = $this->getOnlinePaymentHandler($patron['source'])) {
            return;
        }

        $callback = function ($fine) {
            return $fine['payableOnline'];
        };
        $payableFines = array_filter($fines, $callback);

        $view->onlinePayment = true;
        $view->paymentHandler = $onlinePayment->getHandlerName($patron['source']);
        $view->transactionFee = isset($paymentConfig['transactionFee'])
            ? $paymentConfig['transactionFee'] : 0;
        $view->minimumFee = isset($paymentConfig['minimumFee'])
            ? $paymentConfig['minimumFee'] : 0;
        $view->payableOnline = $payableOnline['amount'];
        $view->payableTotal = $payableOnline['amount'] + $view->transactionFee;
        $view->payableOnlineCnt = count($payableFines);
        $view->nonPayableFines = count($fines) != count($payableFines);

        $paymentParam = 'payment';
        $request = $this->getRequest();
        $pay = $this->formWasSubmitted('pay-confirm');
        $payment = $request->getQuery()->get(
            $paymentParam, $request->getPost($paymentParam)
        );
        if ($pay && $session && $payableOnline
            && $payableOnline['payable'] && $payableOnline['amount']
        ) {
            // Payment started, check that fee list has not been updated
            if ($this->checkIfFinesUpdated($patron, $fines)) {
                // Fines updated, redirect and show updated list.
                $session->payment_fines_changed = true;
                header("Location: " . $this->getServerUrl('myresearch-fines'));
                exit();
            }
            $finesUrl = $this->getServerUrl('myresearch-fines');
            $ajaxUrl = $this->getServerUrl('home') . 'AJAX';
            list($driver, ) = explode('.', $patron['cat_username'], 2);

            $user = $this->getUser();
            if (!$user) {
                return;
            }

            // Start payment
            $result = $paymentHandler->startPayment(
                $finesUrl,
                $ajaxUrl,
                $user,
                $patron['cat_username'],
                $driver,
                $payableOnline['amount'],
                $view->transactionFee,
                $payableFines,
                $paymentConfig['currency'],
                $paymentParam
            );
            if (!$result) {
                $this->flashMessenger()->addMessage(
                    'online_payment_failed', 'error'
                );
                header("Location: " . $this->getServerUrl('myresearch-fines'));
            }
            exit();
        } elseif ($payment) {
            // Payment response received.

            // AJAX/onlinePaymentNotify was called before the user returned to Finna.
            // Display success message and return since the transaction is already
            // processed.
            if (!$payableOnline) {
                $this->flashMessenger()->addMessage(
                    'online_payment_successful', 'success'
                );
                return;
            }

            //  Display page and process via AJAX.
            $view->registerPayment = true;
            $view->registerPaymentParams
                = $this->getRequest()->getQuery()->toArray();
        } else {
            $allowPayment
                = $paymentPermittedForUser === true && $payableOnline
                && $payableOnline['payable'] && $payableOnline['amount'];

            // Display possible warning and store fines to session.
            $this->storeFines($patron, $fines);
            $session = $this->getOnlinePaymentSession();
            $view->transactionId = $session->sessionId;

            if (!empty($session->payment_fines_changed)) {
                $view->paymentFinesChanged = true;
                $this->flashMessenger()->addMessage(
                    'online_payment_fines_changed', 'error'
                );
                unset($session->payment_fines_changed);
            } elseif (!empty($session->paymentOk)) {
                $this->flashMessenger()->addMessage(
                    'online_payment_successful', 'success'
                );
                unset($session->paymentOk);
            } else {
                $view->onlinePaymentEnabled = $allowPayment;
                if ($paymentPermittedForUser !== true) {
                    $this->flashMessenger()->addMessage(
                        strip_tags($paymentPermittedForUser), 'error'
                    );
                } elseif (!empty($payableOnline['reason'])) {
                    $view->nonPayableReason = $payableOnline['reason'];
                } elseif ($this->formWasSubmitted('pay')) {
                    $view->setTemplate(
                        'Helpers/OnlinePayment/terms-' . $view->paymentHandler
                        . '.phtml'
                    );
                }
            }
        }
    }

    /**
     * Process payment request.
     *
     * @param Zend\Http\Request $request Request
     *
     * @return array Associative array with keys
     *   - 'success' (boolean)
     *   - 'msg' (string) error message if payment could not be processed.
     */
    protected function processPayment($request)
    {
        $params = array_merge(
            $request->getQuery()->toArray(), $request->getPost()->toArray()
        );

        if (!isset($params['driver'])) {
            $this->handleError(
                'Error processing payment: missing parameter "driver" in response.'
            );
            return ['success' => false];
        }

        $driver = $params['driver'];

        $handler = $this->getOnlinePaymentHandler($driver);
        if (!$handler) {
            $this->handleError(
                'Error processing payment: could not initialize payment'
                . " handler $handlerName"
            );
            return ['success' => false];
        }

        $params = $handler->getPaymentResponseParams($request);
        $transactionId = $params['transaction'];

        $tr = $this->getTable('transaction');
        if (!$t = $tr->getTransaction($transactionId)) {
            $this->handleError(
                "Error processing payment: transaction $transactionId not found"
            );
            return ['success' => false];
        }

        if (!$tr->isTransactionInProgress($transactionId)) {
            $this->handleError(
                'Error processing payment: '
                . "transaction $transactionId already processed."
            );
            return ['success' => false];
        }

        $driver = $t['driver'];
        $patronId = $t->cat_username;
        $catalog = $this->getILS();

        if (!is_array($patron = $this->catalogLogin())) {
            $userTable = $this->getTable('User');
            $user
                = $userTable->select(
                    ['cat_username' => $t['cat_username'], 'id' => $t['user_id']]
                )->current();

            try {
                $patron = $catalog->patronLogin(
                    $user['cat_username'], $user->getCatPassword()
                );
            } catch (\Exception $e) {
                $this->handleException($e);
            }
        }

        $res = $handler->processResponse($request);
        $transactionTable = $this->getTable('transaction');

        if (!$patron) {
            $this->handleError(
                'Error processing transaction id ' . $t['id']
                . ': patronLogin error (cat_username: ' . $user['cat_username']
                . ', user id: ' . $t['user_id'] . ')'
            );

            $transactionTable->setTransactionRegistrationFailed(
                $t['transaction_id'], 'patronLogin error'
            );
            return ['success' => false];
        }

        if (!is_array($res) || empty($res['markFeesAsPaid'])) {
            return ['success' => false, 'msg' => $res];
        }

        $tId = $res['transactionId'];
        try {
            $finesAmount = $catalog->getOnlinePayableAmount($patron);
        } catch (\Exception $e) {
            $this->handleException($e);
            return ['success' => false];
        }

        // Check that payable sum has not been updated
        if ($finesAmount['payable']
            && !empty($finesAmount['amount']) && !empty($res['amount'])
            && $finesAmount['amount'] != $res['amount']
        ) {
            // Payable sum updated. Skip registration and inform user
            // that payment processing has been delayed.
            if (!$transactionTable->setTransactionFinesUpdated($tId)) {
                $this->handleError(
                    "Error updating transaction $transactionId"
                    . " status: payable sum updated"
                );
            }
            return [
                'success' => false,
                'msg' => 'online_payment_registration_failed'
            ];
        }

        try {
            $catalog->markFeesAsPaid($patron, $res['amount'], $tId);
            if (!$transactionTable->setTransactionRegistered($tId)) {
                $this->handleError(
                    "Error updating transaction $transactionId status: registered"
                );
            }
            $session = $this->getOnlinePaymentSession();
            $session->paymentOk = true;
        } catch (\Exception $e) {
            $this->handleError(
                'SIP2 payment error (patron ' . $patron['id'] . '): '
                . $e->getMessage()
            );
            $this->handleException($e);

            $result = $transactionTable->setTransactionRegistrationFailed(
                $tId, $e->getMessage()
            );
            if (!$result) {
                $this->handleError(
                    "Error updating transaction $transactionId status: "
                    . 'registering failed'
                );
            }
            return ['success' => false, 'msg' => $e->getMessage()];
        }
        return ['success' => true];
    }

    /**
     * Store fines to session.
     *
     * @param object $patron Patron.
     * @param array  $fines  Listing of fines.
     *
     * @return void
     */
    protected function storeFines($patron, $fines)
    {
        $session = $this->getOnlinePaymentSession();
        $session->sessionId = $this->generateFingerprint($patron);
        $session->fines = $this->generateFingerprint($fines);
    }

    /**
     * Log error message.
     *
     * @param string $msg Error message.
     *
     * @return void
     */
    protected function handleError($msg)
    {
        $this->setLogger($this->serviceLocator->get('VuFind\Logger'));
        $this->logError($msg);
    }

    /**
     * Log exception.
     *
     * @param Exception $e Exception
     *
     * @return void
     */
    protected function handleException($e)
    {
        $this->setLogger($this->serviceLocator->get('VuFind\Logger'));
        if (!Console::isConsole()) {
            $this->logger->logException($e, new \Zend\Stdlib\Parameters());
        } else {
            $this->logException($e);
        }
    }
}
