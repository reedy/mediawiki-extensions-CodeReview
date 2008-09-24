<?php
/**
 * Internationalisation file for extension CodeReview.
 *
 * @file
 * @ingroup Extensions
 */

$messages = array();

$messages['en'] = array(
	'code' => 'Code Review',
	'code-comments' => 'Comments',
	'code-desc' => 'This tells you what I do on Special:Version. Provide links to special page(s) if possible',
	'code-no-repo' => 'No repository configured!',
	'code-field-id' => 'Revision',
	'code-field-author' => 'Author',
	'code-field-message' => 'Comment',
	'code-field-status' => 'Status',
	'code-field-timestamp' => 'Date',
	'code-rev-author' => 'Author:',
	'code-rev-message' => 'Comment:',
	'code-rev-repo' => 'Repository:',
	'code-rev-rev' => 'Revision:',
	'code-rev-rev-viewvc' => 'on ViewVC',
	'code-rev-paths' => 'Modified paths:',
	'code-rev-modified-a' => 'added',
	'code-rev-modified-c' => 'copied',
	'code-rev-modified-d' => 'deleted',
	'code-rev-modified-m' => 'modified',
	'code-rev-status' => 'Status:',
	'code-rev-status-set' => 'Change status',
	'code-rev-tags' => 'Tags:',
	'code-rev-tag-add' => 'Add tag',
	'code-rev-comment-by' => 'Comment by $1',
	'code-rev-comment-submit' => 'Submit comment',
	'code-rev-comment-preview' => 'Preview',
	'code-rev-diff' => 'Diff',
	'code-rev-diff-link' => 'diff',
	'code-status-new' => 'new',
	'code-status-fixme' => 'fixme',
	'code-status-resolved' => 'resolved',
	'code-status-ok' => 'ok',
	
	'codereview-reply-link' => 'reply',

	'repoadmin' => 'Repository Administration',
	'repoadmin-new-legend' => 'Create a new repository',
	'repoadmin-new-label' => 'Repository name:',
	'repoadmin-new-button' => 'Create',
	'repoadmin-edit-legend' => 'Modification of repository "$1"',
	'repoadmin-edit-path' => 'Repository path:',
	'repoadmin-edit-bug' => 'Bugzilla path:',
	'repoadmin-edit-view' => 'ViewVC path:',
	'repoadmin-edit-button' => 'OK',
	'repoadmin-edit-sucess' => 'The repository "[[Special:Code/$1|$1]]" has been sucessfully modified.',

	'right-repoadmin' => 'Manage code repositories',
	'right-codereview-add-tag' => 'Add new tags to revisions',
	'right-codereview-remove-tag' => 'Remove tags from revisions',
	'right-codereview-post-comment' => 'Add comments on revisions',
	'right-codereview-set-status' => 'Change revisions status',
);

$messages['fr'] = array(
	'code' => 'Vérification du code',
	'code-comments' => 'Commentaires',
	'code-no-repo' => 'Pas de dépôt configuré !',
	'code-field-id' => 'Révision',
	'code-field-author' => 'Auteur',
	'code-field-message' => 'Commentaire',
	'code-field-status' => 'Statut',
	'code-field-timestamp' => 'Date',
	'code-rev-author' => 'Auteur :',
	'code-rev-comment-by' => 'Commentaire par $1',
	'code-rev-comment-submit' => 'Ajouter le commentaire',
	'code-rev-comment-preview' => 'Prévisualisation',
	'code-rev-diff' => 'Différence',
	'code-rev-diff-link' => 'diff',
	'code-rev-message' => 'Commentaire :',
	'code-rev-repo' => 'Dépôt :',
	'code-rev-rev' => 'Révision :',
	'code-rev-rev-viewvc' => 'sur ViewVC',
	'code-rev-paths' => 'Fichiers/dossiers modifiés :',
	'code-rev-modified-a' => 'ajouté',
	'code-rev-modified-c' => 'copié',
	'code-rev-modified-d' => 'supprimé',
	'code-rev-modified-m' => 'modifié',
	'code-rev-status' => 'Statut :',
	'code-rev-status-set' => 'Changer le statut',
	'code-rev-tag-add' => 'Ajouter l\'attribut',
	'code-rev-tags' => 'Attributs :',
	'code-status-new' => 'nouveau',
	'code-status-fixme' => 'a réparer',
	'code-status-resolved' => 'résolu',
	'code-status-ok' => 'ok',

	'repoadmin' => 'Administration des dépôts',
	'repoadmin-new-legend' => 'Créer un nouveau dépôt',
	'repoadmin-new-label' => 'Nom du dépôt:',
	'repoadmin-new-button' => 'Créer',
	'repoadmin-edit-legend' => 'Modification du dépôt "$1"',
	'repoadmin-edit-path' => 'Chemin du dépôt :',
	'repoadmin-edit-bug' => 'Chemin de Bugzilla :',
	'repoadmin-edit-view' => 'Chemin de ViewVC :',
	'repoadmin-edit-button' => 'Valider',
	'repoadmin-edit-sucess' => 'Le dépôt "[[Special:Code/$1|$1]]" a été modifié avec succès.',

	'right-repoadmin' => 'Administrer les dépôts de code',
	'right-codereview-add-tag' => 'Ajouter de nouveaux attributs aux révision',
	'right-codereview-remove-tag' => 'Enlever de attributs aux révision',
	'right-codereview-post-comment' => 'Ajouter un commentaire aux révisions',
	'right-codereview-set-status' => 'Changer le statut des revisions',
);
