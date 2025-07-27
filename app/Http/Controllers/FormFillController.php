<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FormFillController extends Controller
{
    public function show()
    {
        return view('form-fill');
    }

    public function submit(Request $request)
    {
        set_time_limit(600); // Increase execution time to 5 minutes

        $request->validate([
            'responses' => 'required|integer|min:1',
        ]);

        $responses = $request->input('responses');
        $result = $this->fillGoogleForm($responses);

        return redirect()->route('form.show')->with('success', $result['message']);
    }

    private function findNextButton($driver)
    {
        $selectors = [
            'button[jsname="LgbsSe"]',
            'button[data-idom-class="VfPpkd-LgbsSe"]',
            'button.VfPpkd-LgbsSe',
            'button[aria-label="Next"]',
            '//div[@role="button"]//span[contains(text(), "Next")]' // XPath for corrected selector
        ];
        foreach ($selectors as $selector) {
            try {
                $by = strpos($selector, '//') === 0 ? WebDriverBy::xpath($selector) : WebDriverBy::cssSelector($selector);
                $driver->wait(20, 500)->until(
                    WebDriverExpectedCondition::elementToBeClickable($by)
                );
                $element = $driver->findElement($by);
                // Log button state
                $isEnabled = $element->isEnabled();
                $isDisplayed = $element->isDisplayed();
                $attributes = $element->getAttribute('outerHTML');
                $this->info("Found clickable 'Next' button", [
                    'selector' => $selector,
                    'enabled' => $isEnabled,
                    'displayed' => $isDisplayed,
                    'attributes' => $attributes
                ]);
                return $element;
            } catch (\Exception $e) {
                $this->info("Selector failed", ['selector' => $selector, 'error' => $e->getMessage()]);
            }
        }
        throw new \Exception("No clickable 'Next' button found with any selector");
    }

    private function fillGoogleForm($responses)
    {
        // Remote Selenium server configuration (Sauce Labs)
        $host = '#';
        $this->info("Sauce Labs host URL", ['host' => str_replace(env('SAUCE_ACCESS_KEY'), '****', $host)]);
        $options = new ChromeOptions();
        $options->addArguments([
            '--start-maximized',
            '--disable-blink-features=AutomationControlled',
            '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            '--disable-infobars'
        ]);
        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY_W3C, $options);
        $capabilities->setCapability('browserVersion', 'latest');
        $capabilities->setCapability('platformName', 'Windows 10');
        $capabilities->setCapability('sauce:options', [
            'username' => env('SAUCE_USERNAME'),
            'accessKey' => env('SAUCE_ACCESS_KEY'),
            'name' => 'Google Form Automation',
            'build' => 'Laravel-Automation-' . date('Y-m-d'),
        ]);

        $successfulResponses = 0;
        $errors = [];

        for ($i = 0; $i < $responses; $i++) {
            $driver = null;
            $sessionId = null;
            try {
                $this->info("Starting response attempt", ['response_number' => $i + 1, 'action' => 'start']);
                $driver = RemoteWebDriver::create($host, $capabilities);
                $sessionId = $driver->getSessionID();
                $sauceUrl = "https://app.saucelabs.com/tests/{$sessionId}";
                $this->info("Connected to Sauce Labs", [
                    'response_number' => $i + 1,
                    'action' => 'connect',
                    'session_id' => $sessionId,
                    'sauce_url' => $sauceUrl
                ]);

                // Navigate to the Google Form
                $formUrl = '#';
                $driver->get($formUrl);
                $this->humanLikeDelay(1, 3);
                $this->info("Navigated to form URL", [
                    'response_number' => $i + 1,
                    'action' => 'navigate',
                    'session_id' => $sessionId,
                    'url' => $driver->getCurrentURL(),
                    'title' => $driver->getTitle()
                ]);

                // Handle Google Sign-In
                $maxSignInAttempts = 3;
                $signInAttempt = 0;
                while ($signInAttempt < $maxSignInAttempts && strpos($driver->getCurrentURL(), 'accounts.google.com') !== false) {
                    $this->info("Sign-in page detected", [
                        'response_number' => $i + 1,
                        'action' => 'sign_in_start',
                        'session_id' => $sessionId,
                        'url' => $driver->getCurrentURL()
                    ]);
                    try {
                        // Enter email
                        $driver->wait(10, 500)->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('input[type="email"]'))
                        );
                        $emailField = $driver->findElement(WebDriverBy::cssSelector('input[type="email"]'));
                        $emailField->clear();
                        $email = env('GOOGLE_EMAIL');
                        $emailField->sendKeys($email);
                        // Trigger oninput event
                        $driver->executeScript("arguments[0].dispatchEvent(new Event('input', { bubbles: true }));", [$emailField]);
                        $this->humanLikeDelay(2, 5);
                        $enteredEmail = $emailField->getAttribute('value');
                        $maskedEmail = substr($email, 0, 3) . str_repeat('*', strlen($email) - 6) . substr($email, -3);
                        $this->info("Entered email", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_email',
                            'session_id' => $sessionId,
                            'masked_email' => $maskedEmail,
                            'entered_value' => $enteredEmail
                        ]);

                        // Check for error messages
                        try {
                            $errorMessage = $driver->findElement(WebDriverBy::cssSelector('div.o6cuMc, div[role="alert"]'));
                            $errorText = $errorMessage->getText();
                            throw new \Exception("Sign-in error detected: $errorText");
                        } catch (\Exception $e) {
                            if (strpos($e->getMessage(), 'Sign-in error detected') !== false) {
                                throw $e;
                            }
                            $this->info("No error message found, proceeding", [
                                'response_number' => $i + 1,
                                'action' => 'error_check',
                                'session_id' => $sessionId
                            ]);
                        }

                        // Detect and dismiss overlays
                        try {
                            $overlay = $driver->findElement(WebDriverBy::cssSelector('div[style*="z-index: 1000"], div[class*="overlay"], div[role="dialog"]'));
                            $this->info("Overlay detected, attempting to dismiss", [
                                'response_number' => $i + 1,
                                'action' => 'overlay_detected',
                                'session_id' => $sessionId
                            ]);
                            $driver->executeScript("arguments[0].style.display='none';", [$overlay]);
                        } catch (\Exception $e) {
                            $this->info("No overlay detected", [
                                'response_number' => $i + 1,
                                'action' => 'overlay_skipped',
                                'session_id' => $sessionId
                            ]);
                        }

                        // Save full page source
                        $pageSource = $driver->getPageSource();
                        $timestamp = now()->format('Ymd_His');
                        $fileName = "debug_html_email_{$timestamp}_{$sessionId}.html";
                        Storage::disk('local')->put("logs/{$fileName}", $pageSource);
                        $this->info("Email page source saved", [
                            'response_number' => $i + 1,
                            'action' => 'debug_html_email',
                            'file' => storage_path("app/logs/{$fileName}"),
                            'session_id' => $sessionId
                        ]);

                        // Click Next
                        $nextButton = $this->findNextButton($driver);
                        try {
                            $nextButton->click();
                        } catch (\Exception $e) {
                            $this->info("Direct click failed, attempting JavaScript click", [
                                'response_number' => $i + 1,
                                'action' => 'sign_in_email_next_js',
                                'error' => $e->getMessage(),
                                'session_id' => $sessionId
                            ]);
                            $driver->executeScript("arguments[0].dispatchEvent(new Event('click', { bubbles: true }));", [$nextButton]);
                        }
                        $this->humanLikeDelay(2, 5);
                        $this->info("Clicked Next after email", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_email_next',
                            'session_id' => $sessionId
                        ]);

                        // Wait for password field or CAPTCHA
                        $driver->wait(90, 500)->until(
                            WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('input[type="password"], iframe[title*="CAPTCHA"], div[role="button"]'))
                        );
                        $this->info("Password field or CAPTCHA detected", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_post_email',
                            'session_id' => $sessionId,
                            'url' => $driver->getCurrentURL()
                        ]);

                        // Check for CAPTCHA
                        try {
                            $captchaFrame = $driver->findElement(WebDriverBy::cssSelector('iframe[title*="CAPTCHA"]'));
                            $this->info("CAPTCHA detected, please complete in Sauce Labs dashboard", [
                                'response_number' => $i + 1,
                                'action' => 'captcha_detected',
                                'session_id' => $sessionId,
                                'sauce_url' => $sauceUrl
                            ]);
                            $driver->wait(90, 500)->until(
                                WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('input[type="password"], div[role="button"]'))
                            );
                            $this->info("CAPTCHA completed or bypassed", [
                                'response_number' => $i + 1,
                                'action' => 'captcha_completed',
                                'session_id' => $sessionId
                            ]);
                        } catch (\Exception $e) {
                            $this->info("No CAPTCHA detected, proceeding", [
                                'response_number' => $i + 1,
                                'action' => 'captcha_skipped',
                                'session_id' => $sessionId
                            ]);
                        }

                        // Enter password
                        $passwordField = $driver->findElement(WebDriverBy::cssSelector('input[type="password"]'));
                        $passwordField->clear();
                        $passwordField->sendKeys(env('GOOGLE_PASSWORD'));
                        $this->humanLikeDelay(0.5, 1.5);
                        $this->info("Entered password", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_password',
                            'session_id' => $sessionId
                        ]);
                        $fileName = "debug_html_password_{$timestamp}_{$sessionId}.html";
                        Storage::disk('local')->put("logs/{$fileName}", $driver->getPageSource());
                        $this->info("Password page source saved", [
                            'response_number' => $i + 1,
                            'action' => 'debug_html_password',
                            'file' => storage_path("app/logs/{$fileName}"),
                            'session_id' => $sessionId
                        ]);

                        // Click Next
                        $nextButton = $this->findNextButton($driver);
                        try {
                            $nextButton->click();
                        } catch (\Exception $e) {
                            $this->info("Direct click failed, attempting JavaScript click", [
                                'response_number' => $i + 1,
                                'action' => 'sign_in_password_next_js',
                                'error' => $e->getMessage(),
                                'session_id' => $sessionId
                            ]);
                            $driver->executeScript("arguments[0].dispatchEvent(new Event('click', { bubbles: true }));", [$nextButton]);
                        }
                        $this->humanLikeDelay(2, 5);
                        $this->info("Clicked Next after password", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_password_next',
                            'session_id' => $sessionId
                        ]);

                        // Wait for form or additional prompts
                        $driver->wait(90, 500)->until(
                            WebDriverExpectedCondition::urlContains('docs.google.com/forms')
                        );
                        $this->info("Redirected to form after sign-in", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_success',
                            'session_id' => $sessionId,
                            'url' => $driver->getCurrentURL()
                        ]);
                    } catch (\Exception $e) {
                        $this->info("Sign-in attempt failed", [
                            'response_number' => $i + 1,
                            'action' => 'sign_in_failed',
                            'error' => $e->getMessage(),
                            'session_id' => $sessionId
                        ]);
                        $signInAttempt++;
                        if ($signInAttempt >= $maxSignInAttempts) {
                            throw new \Exception("Failed to complete sign-in after $maxSignInAttempts attempts: " . $e->getMessage());
                        }
                        $this->humanLikeDelay(2, 5);
                    }
                }

                // Wait for form to load
                $this->info("Waiting for form to load", [
                    'response_number' => $i + 1,
                    'action' => 'form_wait',
                    'session_id' => $sessionId,
                    'url' => $driver->getCurrentURL()
                ]);
                $driver->wait(90, 500)->until(
                    WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('form.freebirdFormviewerViewFormContent'))
                );
                $this->info("Form loaded successfully", [
                    'response_number' => $i + 1,
                    'action' => 'form_load',
                    'session_id' => $sessionId,
                    'url' => $driver->getCurrentURL(),
                    'title' => $driver->getTitle()
                ]);
                $fileName = "debug_html_form_{$timestamp}_{$sessionId}.html";
                Storage::disk('local')->put("logs/{$fileName}", $driver->getPageSource());
                $this->info("Form page source saved", [
                    'response_number' => $i + 1,
                    'action' => 'debug_html_form',
                    'file' => storage_path("app/logs/{$fileName}"),
                    'session_id' => $sessionId
                ]);

                // Fill in a text input field (replace with actual entry.xxxx)
                try {
                    $nameField = $driver->findElement(WebDriverBy::cssSelector('input[name="entry.123456789"]')); // Replace with actual name
                    $nameValue = 'John ' . $this->randomString(4);
                    $nameField->sendKeys($nameValue);
                    $this->humanLikeDelay(0.5, 1.5);
                    $this->info("Filled text field", [
                        'response_number' => $i + 1,
                        'action' => 'fill_text',
                        'value' => $nameValue,
                        'session_id' => $sessionId
                    ]);
                } catch (\Exception $e) {
                    $this->info("Text field not found, skipping", [
                        'response_number' => $i + 1,
                        'action' => 'fill_text_failed',
                        'error' => $e->getMessage(),
                        'session_id' => $sessionId
                    ]);
                }

                // Select a multiple-choice option (replace with actual entry.xxxx)
                try {
                    $options = $driver->findElements(WebDriverBy::cssSelector('div[role="radio"][data-answer-name="entry.456789123"]')); // Replace with actual name
                    if (!empty($options)) {
                        $selectedOption = $options[array_rand($options)];
                        $selectedOption->click();
                        $this->humanLikeDelay(0.5, 1.5);
                        $this->info("Selected multiple-choice option", [
                            'response_number' => $i + 1,
                            'action' => 'select_option',
                            'session_id' => $sessionId
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->info("Multiple-choice options not found, skipping", [
                        'response_number' => $i + 1,
                        'action' => 'select_option_failed',
                        'error' => $e->getMessage(),
                        'session_id' => $sessionId
                    ]);
                }

                // Fill in a textarea (replace with actual entry.xxxx)
                try {
                    $textarea = $driver->findElement(WebDriverBy::cssSelector('textarea[name="entry.987654321"]')); // Replace with actual name
                    $textValue = 'This is a natural response by ' . $this->randomString(6) . '.';
                    $textarea->sendKeys($textValue);
                    $this->humanLikeDelay(0.5, 1.5);
                    $this->info("Filled textarea", [
                        'response_number' => $i + 1,
                        'action' => 'fill_textarea',
                        'value' => $textValue,
                        'session_id' => $sessionId
                    ]);
                } catch (\Exception $e) {
                    $this->info("Textarea not found, skipping", [
                        'response_number' => $i + 1,
                        'action' => 'fill_textarea_failed',
                        'error' => $e->getMessage(),
                        'session_id' => $sessionId
                    ]);
                }

                // Submit the form
                try {
                    $submitButton = $driver->findElement(WebDriverBy::cssSelector('div[role="button"].uArJ5')); // Common Google Form submit button
                    $this->humanLikeDelay(0.5, 1.5);
                    $submitButton->click();
                    $this->info("Clicked submit button", [
                        'response_number' => $i + 1,
                        'action' => 'submit',
                        'session_id' => $sessionId
                    ]);
                    $this->humanLikeDelay(1, 2);

                    // Verify submission
                    $driver->wait(10, 500)->until(
                        WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('div.freebirdFormviewerViewResponseConfirmationMessage'))
                    );
                    $this->info("Response submitted successfully", [
                        'response_number' => $i + 1,
                        'action' => 'submit_success',
                        'session_id' => $sessionId,
                        'url' => $driver->getCurrentURL()
                    ]);
                    $successfulResponses++;
                } catch (\Exception $e) {
                    $this->info("Submit button not found or submission failed", [
                        'response_number' => $i + 1,
                        'action' => 'submit_failed',
                        'error' => $e->getMessage(),
                        'session_id' => $sessionId
                    ]);
                    throw $e;
                }

            } catch (\Exception $e) {
                $errors[] = "Response " . ($i + 1) . " failed: " . $e->getMessage();
                $this->info("Response failed", [
                    'response_number' => $i + 1,
                    'action' => 'error',
                    'error' => $e->getMessage(),
                    'session_id' => $sessionId,
                    'url' => $driver ? $driver->getCurrentURL() : 'N/A'
                ]);
            } finally {
                if ($driver) {
                    $this->humanLikeDelay(2, 4); // Increased delay
                    $driver->quit();
                    $this->info("Browser session closed", [
                        'response_number' => $i + 1,
                        'action' => 'session_close',
                        'session_id' => $sessionId
                    ]);
                }
            }
        }

        $message = "Submitted $successfulResponses/$responses responses successfully.";
        if (!empty($errors)) {
            $message .= " Errors: " . implode("; ", $errors);
        }
        $this->info("Automation completed", ['successful' => $successfulResponses, 'total' => $responses, 'errors' => $errors]);
        return ['message' => $message];
    }

    private function randomString($length = 8)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz';
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, strlen($characters) - 1)];
        }
        return ucfirst($randomString);
    }

    private function humanLikeDelay($minSeconds = 0.5, $maxSeconds = 1.5)
    {
        usleep(rand($minSeconds * 1000000, $maxSeconds * 1000000));
    }

    private function info($message, array $context = [])
    {
        Log::info($message, array_merge([
            'timestamp' => now()->toDateTimeString(),
            'controller' => 'FormFillController',
        ], $context));
    }
}
