describe('Experiments templates', () => {
  beforeEach(() => {
    cy.login();
    cy.enableCodeCoverage(Cypress.currentTest.titlePath.join(' '));
  });

  it('Create and edit an experiment template', () => {
    cy.visit('/ucp.php?tab=3');
    // stub the window.prompt() because that's the only way in cypress to input something into a prompt()
    cy.window().then(win => {
      cy.stub(win, 'prompt').returns('Cypress created template');
      // create
      cy.get('button[data-action="create-template"]').click();
      // destroy
      cy.get('div[title="More options"]').click().get('a[data-action="destroy-template"]').click();
    });
  });
});
