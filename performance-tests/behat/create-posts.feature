Feature: Create lots of posts

  Background:
    Given I log in as an admin

  Scenario: Create and publish a blog post


    When I go to "/wp-admin/post-new.php"
    And I fill in "post_title" with a random string "12" characters long
    And I fill in "post_name" with a random string "12" characters long
    And I press "publish"
    Then print current URL
    And I should see "Post published."

    And I open the links to all homepage posts
