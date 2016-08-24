<?php


namespace WPLCache\Behat;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

/**
 * Define application features from the specific context.
 */
class PerformanceHelpers implements Context, SnippetAcceptingContext {

  /** @var \Behat\MinkExtension\Context\MinkContext */
  private $minkContext;

  /** @BeforeScenario */
  public function gatherContexts(BeforeScenarioScope $scope)
  {
      $environment = $scope->getEnvironment();
      $this->minkContext = $environment->getContext('Behat\MinkExtension\Context\MinkContext');
  }

  /**
   * Fills in form field with specified id|name|label|value
   * Example: When I fill in "admin_password2" with a random string "12" characters long
   *
   * @When I fill in :arg1 with a random string :arg2 characters long
   */
  public function fillFieldWithRandomString($field, $length)
  {
      $this->minkContext->fillField($field, $this->rand_string( $length ));
  }

  protected function rand_string( $length ) {
  	$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
    $str = '';
  	$size = strlen( $chars );
  	for( $i = 0; $i < $length; $i++ ) {
  		$str .= $chars[ rand( 0, $size - 1 ) ];
  	}

  	return $str;
  }

}
