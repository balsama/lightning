@lightning @api @workflow @layout @workflow-layout @javascript @test
Feature: Integration of workflows with the In-Place Editor

  @ccabf7f3
  Scenario: IPE should be available for unpublished content
    Given I am logged in as a user with the landing_page_creator role
    And landing_page content:
      | title  | path    | moderation_state |
      | Foobar | /foobar | draft            |
    When I visit "/foobar"
    Then IPE should be enabled

  @dac27b30
  Scenario: IPE should be enabled for published content
    Given I am logged in as a user with the landing_page_creator,landing_page_reviewer roles
    And landing_page content:
      | title  | path    | moderation_state |
      | Foobar | /foobar | draft            |
    When I visit "/foobar"
    And I visit the edit form
    And I select "published" from "moderation_state[0][state]"
    And I press "Save"
    Then IPE should be enabled

  @46d46379
  Scenario: IPE should be enabled on forward revisions
    Given I am logged in as a user with the landing_page_creator,landing_page_reviewer roles
    And landing_page content:
      | title  | path    | moderation_state |
      | Foobar | /foobar | published        |
    When I visit "/foobar"
    And I visit the edit form
    And I select "draft" from "moderation_state[0][state]"
    And I press "Save"
    Then IPE should be enabled

  @66d946c7
  Scenario: IPE should be disabled for published content that has unpublished edits
    Given I am logged in as a user with the landing_page_creator,landing_page_reviewer roles
    And landing_page content:
      | title  | path    | moderation_state |
      | Foobar | /foobar | published        |
    When I visit "/foobar"
    And I visit the edit form
    And I select "draft" from "moderation_state[0][state]"
    And I press "Save"
    And I click "View"
    Then IPE should be disabled
