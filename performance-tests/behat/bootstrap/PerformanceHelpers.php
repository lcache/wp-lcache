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


  /**
   * @When I open the links to all homepage posts
   */
  public function iOpenTheLinksToAllHomepagePosts()
  {

    $this->minkContext->visit("/");


    $next_page_exists = TRUE;
    $next_page_url ='';

    while($next_page_exists) {

      $page = $this->minkContext->getSession()->getPage();
      $next_page_link = $page->find('css', '.next.page-numbers');


      if ($next_page_link) {
        $next_page_url = $next_page_link->getAttribute('href');
      }
      else {
        $next_page_exists = FALSE;
      }

      $this->openAllPostLinksOnASinglePage($page);

      if (!empty($next_page_link)) {
        $this->minkContext->visit($next_page_url);
      }
    }
    echo "last page visited was $next_page_url";
  }




  protected function openAllPostLinksOnASinglePage($page) {



    $post_urls = $this->getAllPostURLs($page);

    foreach ($post_urls as $post_url) {

      $this->minkContext->visit($post_url);

//      echo "\n";
//      echo $this->minkContext->getSession()->getPage()->find('css', 'h1.entry-title')->getHtml();
//      echo "\n";
//      $this->minkContext->printCurrentUrl();
//      echo "\n";
    }

  }



  protected function getAllPostURLs($page) {

    $post_links = $page->findAll('css', 'article h2 a');

    $post_urls = [];
    foreach ($post_links as $post_link) {
      $post_urls[] =$post_link->getAttribute('href');
    }

    return $post_urls;
  }

}
