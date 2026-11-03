@mod @mod_choicegroup
Feature: Toggle the table's columns and delete responses from the report
  In order to read the table of groups and tidy the responses
  As a student and as a teacher
  I need the table's toggles to show what they name and the report's Select all to reach every response

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | student1 | Vinnie    | Student1 | student1@example.com |
      | student2 | Ann       | Student2 | student2@example.com |
      | teacher1 | Darrell   | Teacher1 | teacher1@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
      | teacher1 | C1     | editingteacher |
    And the following "groups" exist:
      | name  | course | idnumber | description         |
      | Alpha | C1     | C1G1     | Meets on Tuesdays.  |
      | Bravo | C1     | C1G2     | Meets on Thursdays. |
    And the following "activities" exist:
      | activity    | name           | intro                      | course | idnumber     |
      | choicegroup | Group choice 1 | Group choice 1 for testing | C1     | choicegroup1 |
    And I am on the "Group choice 1" "choicegroup activity editing" page logged in as teacher1
    And I set the following fields to these values:
      | Show the groups as | A table                                               |
      | Publish results    | Always show results to students                       |
      | Privacy of results | Publish full results, showing names and their choices |
    And I set the field "availablegroups" to "Alpha"
    And I press "Add"
    And I set the field "availablegroups" to "Bravo"
    And I press "Add"
    And I press "Save and return to course"
    And I log out

  @javascript
  Scenario: The table's toggles show and hide the members and the descriptions
    Given the following "group members" exist:
      | user     | group |
      | student2 | C1G1  |
    When I am on the "Group choice 1" "choicegroup activity" page logged in as student1
    Then I should not see "Ann Student2"
    And I should not see "Meets on Tuesdays."
    And I click on "Show group members" "button"
    And I should see "Ann Student2"
    And the "aria-expanded" attribute of "Hide group members" "button" should contain "true"
    And I click on "Hide group members" "button"
    And I should not see "Ann Student2"
    And the "aria-expanded" attribute of "Show group members" "button" should contain "false"
    And I click on "Show descriptions" "button"
    And I should see "Meets on Tuesdays."
    And I should see "Meets on Thursdays."
    And the "aria-expanded" attribute of "Hide descriptions" "button" should contain "true"

  @javascript
  Scenario: Select all ticks every response, and the action menu deletes them
    Given the following "group members" exist:
      | user     | group |
      | student1 | C1G1  |
      | student2 | C1G2  |
    When I am on the "Group choice 1" "mod_choicegroup > report" page logged in as teacher1
    Then I should see "Vinnie Student1"
    And I should see "Ann Student2"
    And I click on "Select all" "button"
    And I set the field "With selected" to "Delete"
    And I should not see "Vinnie Student1"
    And I should not see "Ann Student2"
