Feature: WP LCache

  Scenario: LCache should be configured on Pantheon
    Given I log in as an admin
    And I should not see "which is required by WP LCache object cache"

  Scenario: LCache debug should include 'LCache Calls'

    When I am on the homepage
    Then I should not see "LCache Calls:"
    And I should not see "Cache Hits:"
    And I should not see "Cache Misses:"

    When I am on "/?lcache_debug"
    Then I should see "LCache Calls:"
    And I should see "Cache Hits:"
    And I should see "Cache Misses:"
    And I should see "- get:"
