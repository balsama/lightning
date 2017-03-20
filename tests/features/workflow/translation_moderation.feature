@lightning @workflow @multilingual @api
Feature: Tests Lightning Workflow when more than one language is present.

  Scenario: Editing draft of LanguageA does not affect existing content of LanguageB
    Given I am logged in as a user with the administrator role
    And I enable the "Language, Content Translation" modules
    And I add the Filipino language
    And I enable translation for Content -> Basic page -> All fields
    And page content:
      | title  | path    | moderation_state |
      | Foobar | /foobar | published        |
    And I add a Filipino translation for Foobar
    And I create a new draft of the en version of Foobar
    And I create a new draft of the fil version of Foobar
    And I publish the en version of Foobar
    And I visit "/fil/foobar"
    Then the response status code should not be 401
    And the response status code should be 200
