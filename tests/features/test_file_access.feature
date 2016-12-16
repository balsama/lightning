@lightning
Feature: Ensures certain directories' files are not web accessible

  Scenario: Users should not be able to access test files via http
    Given I am on "/profiles/lightning/tests/files/test.jpg"
    Then the response status code should be 403