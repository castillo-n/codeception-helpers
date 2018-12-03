<?php
namespace Codeception\Module;

use Codeception\Module as CodeceptionModule;
use Pbc\Bandolier\Type\Arrays as BandolierArrays;
use utilphp\util as Utilities;


/**
 * Class WpHelper
 * @package Codeception\Module
 * @backupGlobals disabled
 */
class WordPressHelper extends CodeceptionModule
{

    const TEXT_WAIT_TIMEOUT = 30;

    /**
     * Log a user into the Wordpress backend
     *
     * @param \AcceptanceTester|\FunctionalTester   $I
     * @param string                                $loginPath      The login path (usually /wp-login.php)
     * @param string                                $user           Username to login with
     * @param string                                $pass           Password to login with
     * @param int                                   $maxAttempts    The maximum amount of times to attempt to login before failing
     */
    public function logIntoWpAdmin(
        $I,
        $loginPath = "/wp-login.php",
        $user = "admin",
        $pass = "password",
        $maxAttempts = 3
    ) {
        for ($i = 0; $i <= $maxAttempts; $i++) {
            try {
                $I->amOnPage($loginPath);
                $this->fillLoginAndWaitForDashboard($I, $user, $pass);
                $I->waitForText('Dashboard', self::TEXT_WAIT_TIMEOUT);
                return;
            } catch (\Exception $e) {
                if ($i === $maxAttempts) {
                    $I->fail("{$i} login attempts were made.");
                }
                continue;
            }
        }
    }

    /**
     * @param \AcceptanceTester|\FunctionalTester   $I
     * @param string                                $user   Username to login with
     * @param string                                $pass   Password to login with
     */
    private function fillLoginAndWaitForDashboard($I, $user = "admin", $pass = "password")
    {
        $I->waitForJs('return document.readyState == "complete"', $this::TEXT_WAIT_TIMEOUT);
        $I->fillField(['id' => 'user_login'], $user);
        $I->fillField(['id' => 'user_pass'], $pass);
        $I->checkOption("#rememberme");
        $I->click(['id' => 'wp-submit']);
        $I->waitForElementNotVisible("#rememberme", $this::TEXT_WAIT_TIMEOUT * 2);
    }

    /**
     * @param \AcceptanceTester|\FunctionalTester   $I
     * @param string                                $file       Name of the file to be uploaded in the test/_data folder
     * @param string                                $adminPath  Path to the admin backend (usually /wp-admin)
     * @return array
     */
    public function createAnAttachment($I, $file, $adminPath='/wp-admin') {
        $fileNameParts = explode('.', $file);
        $newFileName = substr(md5(time()), 0, 10) . '.' . end($fileNameParts);
        $newFileNameParts = explode('.', $newFileName);
        // create a copy of the file with a unique file name
        copy(dirname(__FILE__, 3) . '/_data/'. $file, dirname(__FILE__, 3) . '/_data/'. $newFileName);

        $I->amOnPage($adminPath . '/media-new.php?browser-uploader');
        $I->attachFile(['id' => 'async-upload'], $newFileName);
        $I->click(['id' => 'html-upload']);
        $I->see('Media Library');
        $I->click($newFileNameParts[0]);
        return [
            'attachment_url' => $I->grabValueFrom(['id' => 'attachment_url'])
        ];
    }


    /**
     * @param \AcceptanceTester|\FunctionalTester   $I
     * @param string|array|null                     $title      Either the post title or an array of the attributes to use on the post
     * @param string|null                           $content    Body content of the page (if not in the $title variable)
     * @param string                                $adminPath  Path to the admin backend (usually /wp-admin)
     */
    public function createAPost($I, $title = null, $content = null, $adminPath='/wp-admin')
    {

        $faker = \Faker\Factory::create();
        if (is_array($title)) {
            extract(BandolierArrays::defaultAttributes([
                    'title' => $faker->sentence(),
                    'content' => $faker->paragraph(),
                    'meta' => [],
                    'featured_image' => null,
                    'customFields' => []
                ]
                , $title)
            );
        }

        $I->amOnPage($adminPath . '/post-new.php');
        // show the settings dialog link
        $I->waitForElementVisible(['id' => 'show-settings-link']);
        $I->click(['id' => 'show-settings-link']);

        $I->scrollTo(['id' => 'title']);
        $I->fillField(['id' => 'title'], $title);
        $exist = Utilities::str_to_bool($I->executeJS("return !!document.getElementById('content-html')"));
        if ($exist) {
            $I->click(['id' => 'content-html']);
            $I->wait(5);
        }
        $I->click(['id' => 'content']);
        $I->fillField(['id' => 'content'], $content);

        // run though the meta field and set any extra fields that is contains
        if (isset($meta) && count($meta) > 0) {
            $I->scrollTo(['id' => 'screen-options-wrap']);
            for($i=0, $iCount=count($meta); $i < $iCount; $i++) {
                $I->{$meta[$i][0]}($meta[$i][1],$meta[$i][2]);
            }
        }
        // run though the custom fields. since there's no good way to know what
        // the name/id of the input is they will be looked up via the value
        if (isset($customFields) && count($customFields) > 0) {
            $I->scrollTo('#postcustom');
            for($i=0, $iCount=count($customFields); $i < $iCount; $i++) {
                try {
                    // try and fill an existing custom field
                    $I->fillField('#' . str_replace('key', 'value',
                            $I->executeJS('return document.querySelectorAll(\'input[value="' . $customFields[$i][0] . '"]\')[0].id;')),
                        $customFields[$i][1]);
                } catch (\Exception $ex) {
                    // make a new one if the above threw an exception
                    $I->click(['id' => 'enternew']);
                    $I->fillField(['id' =>'metakeyinput'], $customFields[$i][0]);
                    $I->fillField(['id' =>'metavalue'], $customFields[$i][1]);
                    $I->click(['id' => 'newmeta-submit']);

                }
            }
        }

        // add featured image if it's set
        if (isset($featured_image) && is_string($featured_image)) {
            $I->click('Set featured image');
            $I->wait(5);
            //$I->click(['aria-label' => $featured_image]);
            $I->executeJS('$(\'li[aria-label="'. $featured_image .'"]\').click()');
            $I->click('#__wp-uploader-id-2 .media-button');
            $I->waitForElementVisible(['id' => 'set-post-thumbnail'], self::TEXT_WAIT_TIMEOUT);
        }

        $I->wait(5);
        $I->scrollTo(['id' => 'submitpost']);
        $I->click(['id' => 'publish']);
        $I->waitForText('Post published', self::TEXT_WAIT_TIMEOUT);
        $I->see('Post published');
        $path = $I->executeJS('return document.querySelector("#sample-permalink > a").getAttribute("href")');
        $I->amOnPage(parse_url($path, PHP_URL_PATH));
    }
}