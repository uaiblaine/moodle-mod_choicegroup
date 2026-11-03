@mod @mod_choicegroup
Feature: Choose a group from the card layout
  In order to find my group among many
  As a student
  I need to search the cards, read a group's details and choose it from there

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Vinnie    | Student1 | student1@example.com |
      | teacher1 | Darrell   | Teacher1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "groups" exist:
      | name    | course | idnumber | description                 |
      | Alpha   | C1     | C1G1     | Meets on Tuesdays.          |
      | Bravo   | C1     | C1G2     | Meets on Thursdays.         |
      | Charlie | C1     | C1G3     | Meets on Saturday mornings. |
    And the following "activities" exist:
      | activity    | name           | intro                      | course | idnumber     |
      | choicegroup | Group choice 1 | Group choice 1 for testing | C1     | choicegroup1 |
    And I am on the "Group choice 1" "choicegroup activity editing" page logged in as teacher1
    And I set the following fields to these values:
      | Show the groups as | Cards with search and filters |
      | Search by name     | Always                        |
    And I set the field "availablegroups" to "Alpha"
    And I press "Add"
    And I set the field "availablegroups" to "Bravo"
    And I press "Add"
    And I set the field "availablegroups" to "Charlie"
    And I press "Add"
    And I press "Save and return to course"
    And I log out

  @javascript
  Scenario: Search the cards, open the details and choose the group from them
    Given I am on the "Group choice 1" "choicegroup activity" page logged in as student1
    And I should see "Showing 3 of 3 groups"
    When I set the field "Search by name" to "rav"
    Then I should see "1 groups found"
    And I should see "Bravo" in the ".mod_choicegroup-cards" "css_element"
    And I should not see "Alpha" in the ".mod_choicegroup-cards" "css_element"
    And I click on "Clear filters" "button"
    And I should see "3 groups found"
    And I click on "Details of Charlie" "button"
    And I should see "Meets on Saturday mornings." in the "Charlie" "dialogue"
    And I click on "Choose this group" "button" in the "Charlie" "dialogue"
    And the field "Charlie" matches value "1"
    And I press "Save my choice"
    And I should see "Your choice has been saved"
    And I should see "Your selection: Charlie"

  @javascript
  Scenario: A choice made on a card replaces the previous one when updates are allowed
    Given I am on the "Group choice 1" "choicegroup activity editing" page logged in as teacher1
    And I set the field "Allow choice to be updated" to "Yes"
    And I press "Save and display"
    And I log out
    And I am on the "Group choice 1" "choicegroup activity" page logged in as student1
    And I set the field "Alpha" to "1"
    And I press "Save my choice"
    When I set the field "Bravo" to "1"
    And I press "Save my choice"
    Then I should see "Your selection: Bravo"
