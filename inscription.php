<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$errors = [];
$success = false;
$databaseReady = true;
$formations = [];
$selectedFormation = null;
$values = [
    'first_name' => '',
    'last_name' => '',
    'date_of_birth' => '',
    'gender' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'formation_id' => '',
    'education_level' => '',
    'message' => '',
];

try {
    $formations = db()->query(
        'SELECT id, title FROM formations WHERE is_published = 1 ORDER BY sort_order, title'
    )->fetchAll();
} catch (Throwable $error) {
    error_log('Home School application page: ' . $error->getMessage());
    $databaseReady = false;
}

$requestedFormation = trim((string) ($_GET['formation'] ?? ''));
if ($requestedFormation !== '' && $databaseReady) {
    $statement = db()->prepare(
        'SELECT id FROM formations WHERE slug = :slug AND is_published = 1'
    );
    $statement->execute(['slug' => $requestedFormation]);
    $values['formation_id'] = (string) ($statement->fetchColumn() ?: '');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    try {
        verify_csrf($_POST['csrf_token'] ?? null);
        $values['first_name'] = trim($values['first_name']);
        $values['last_name'] = trim($values['last_name']);
        $values['phone'] = trim($values['phone']);
        $values['email'] = trim($values['email']);
        if (mb_strlen($values['first_name']) < 2 || mb_strlen($values['first_name']) > 100
            || mb_strlen($values['last_name']) < 2 || mb_strlen($values['last_name']) > 100) {
            throw new InvalidArgumentException('Saisissez un prénom et un nom valides.');
        }
        if (!preg_match('/^[0-9+().\s-]{6,40}$/', $values['phone'])) {
            throw new InvalidArgumentException('Saisissez un numéro de téléphone valide.');
        }
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($values['email']) > 190) {
            throw new InvalidArgumentException('Saisissez une adresse e-mail valide.');
        }
        if ($values['date_of_birth'] !== '') {
            $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $values['date_of_birth']);
            if (!$birthDate || $birthDate->format('Y-m-d') !== $values['date_of_birth']
                || $birthDate > new DateTimeImmutable('today')) {
                throw new InvalidArgumentException('La date de naissance n’est pas valide.');
            }
        }
        if (!in_array($values['gender'], ['', 'female', 'male', 'other'], true)) {
            throw new InvalidArgumentException('Le choix du sexe est invalide.');
        }
        if (mb_strlen($values['address']) > 255 || mb_strlen($values['education_level']) > 120
            || mb_strlen($values['message']) > 5000) {
            throw new InvalidArgumentException('Un ou plusieurs champs sont trop longs.');
        }
        if (!ctype_digit($values['formation_id'])) {
            throw new InvalidArgumentException('Sélectionnez une formation disponible.');
        }
        if (($_POST['privacy'] ?? '') !== 'accepted') {
            throw new InvalidArgumentException('Votre consentement est nécessaire pour envoyer le dossier.');
        }

        $selectedCourse = db()->prepare(
            'SELECT id FROM formations WHERE id = :id AND is_published = 1'
        );
        $selectedCourse->execute(['id' => (int) $values['formation_id']]);
        $formationId = $selectedCourse->fetchColumn();
        if (!$formationId) {
            throw new InvalidArgumentException('La formation sélectionnée n’est plus disponible.');
        }

        $document = upload_file(
            $_FILES['document'] ?? [],
            'documents',
            [
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            ],
            5 * 1024 * 1024
        );

        try {
            $statement = db()->prepare(
                'INSERT INTO inscriptions
                 (first_name, last_name, date_of_birth, gender, phone, email, address,
                  formation_id, education_level, message, document_path, document_original_name)
                 VALUES
                 (:first_name, :last_name, :date_of_birth, :gender, :phone, :email, :address,
                  :formation_id, :education_level, :message, :document_path, :document_original_name)'
            );
            $statement->execute([
                'first_name' => $values['first_name'],
                'last_name' => $values['last_name'],
                'date_of_birth' => $values['date_of_birth'] ?: null,
                'gender' => $values['gender'],
                'phone' => $values['phone'],
                'email' => $values['email'],
                'address' => $values['address'],
                'formation_id' => (int) $formationId,
                'education_level' => $values['education_level'],
                'message' => $values['message'],
                'document_path' => $document['path'] ?? null,
                'document_original_name' => $document['original_name'] ?? null,
            ]);
        } catch (Throwable $error) {
            if ($document && is_file(__DIR__ . '/' . $document['path'])) {
                unlink(__DIR__ . '/' . $document['path']);
            }
            throw $error;
        }

        $success = true;
        $values = array_fill_keys(array_keys($values), '');
    } catch (PDOException $error) {
        error_log('Home School application database: ' . $error->getMessage());
        $errors[] = 'Votre demande n’a pas pu être enregistrée. Réessayez plus tard ou contactez HOMESCHOOL.';
    } catch (InvalidArgumentException|RuntimeException $error) {
        $errors[] = $error->getMessage();
    } catch (Throwable $error) {
        error_log('Home School application submission: ' . $error->getMessage());
        $errors[] = 'Votre demande n’a pas pu être enregistrée. Réessayez plus tard ou contactez HOMESCHOOL.';
    }
}

if ($databaseReady && ctype_digit($values['formation_id'])) {
    try {
        $statement = db()->prepare(
            'SELECT title, description, duration, program, admission_requirements, candidate_info
             FROM formations WHERE id = :id AND is_published = 1'
        );
        $statement->execute(['id' => (int) $values['formation_id']]);
        $selectedFormation = $statement->fetch() ?: null;
    } catch (Throwable $error) {
        error_log('Home School selected course details: ' . $error->getMessage());
    }
}
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#060691">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <meta name="apple-mobile-web-app-title" content="HOME SCHOOL">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="assets/images/logo/pwa-icon-180.png">
  <title>Inscription | HOMESCHOOL</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <link href="assets/css/style.css" rel="stylesheet">
  <link href="assets/css/pwa.css" rel="stylesheet">
  <link href="assets/css/site-images.css" rel="stylesheet">
  <link rel="icon" type="image/png" href="assets/images/logo/favicon.png">
</head>
<body>
  <a class="skip-link" href="#main">Aller au contenu</a>
  <nav class="navbar navbar-expand-lg fixed-top site-nav" aria-label="Navigation principale">
    <div class="container">
      <a class="navbar-brand brand" href="index.html"><img class="brand-logo" src="assets/images/logo/logo-officiel-bleu.png" alt="HOMESCHOOL"></a>
      <a class="btn btn-outline-primary-custom" href="formations.html">Retour aux formations</a>
    </div>
  </nav>
  <main id="main">
    <section class="page-hero"><div class="container">
      <span class="eyebrow eyebrow-light">CANDIDATURE</span>
      <h1>Demande d’<span>inscription.</span></h1>
      <p>Renseignez vos coordonnées. L’équipe HOMESCHOOL vous recontactera pour la suite.</p>
    </div></section>
    <section class="section-pad section-white"><div class="container">
      <div class="row justify-content-center"><div class="col-lg-9">
        <?php if ($success): ?>
          <div class="alert alert-success" role="status">Votre demande a bien été enregistrée. L’équipe HOMESCHOOL vous recontactera.</div>
        <?php endif; ?>
        <?php foreach ($errors as $error): ?>
          <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endforeach; ?>
        <?php if (!$databaseReady): ?>
          <div class="alert alert-warning" role="alert">Le service d’inscription est indisponible. Vérifiez la base de données locale et réessayez.</div>
        <?php elseif (!$formations): ?>
          <div class="alert alert-warning" role="status">Aucune formation n’est actuellement publiée. Contactez HOMESCHOOL pour plus d’informations.</div>
        <?php endif; ?>
        <?php if ($selectedFormation): ?>
          <div class="alert alert-info"><h2 class="h5"><?= e($selectedFormation['title']) ?></h2>
            <?php foreach (['description' => 'Présentation', 'duration' => 'Durée', 'program' => 'Programme', 'admission_requirements' => 'Conditions d’admission', 'candidate_info' => 'Informations candidates'] as $key => $label): ?>
              <?php if (trim((string) $selectedFormation[$key]) !== ''): ?><p class="mb-1"><strong><?= e($label) ?> :</strong> <?= nl2br(e($selectedFormation[$key])) ?></p><?php endif; ?>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <form class="contact-form" method="post" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label" for="first_name">Prénom *</label><input class="form-control" id="first_name" name="first_name" required maxlength="100" autocomplete="given-name" value="<?= e($values['first_name']) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="last_name">Nom *</label><input class="form-control" id="last_name" name="last_name" required maxlength="100" autocomplete="family-name" value="<?= e($values['last_name']) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="date_of_birth">Date de naissance</label><input class="form-control" id="date_of_birth" name="date_of_birth" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e($values['date_of_birth']) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="gender">Sexe (facultatif)</label><select class="form-select" id="gender" name="gender"><option value="">Non précisé</option><option value="female" <?= $values['gender'] === 'female' ? 'selected' : '' ?>>Féminin</option><option value="male" <?= $values['gender'] === 'male' ? 'selected' : '' ?>>Masculin</option><option value="other" <?= $values['gender'] === 'other' ? 'selected' : '' ?>>Autre</option></select></div>
            <div class="col-md-6"><label class="form-label" for="phone">Téléphone *</label><input class="form-control" id="phone" name="phone" type="tel" required maxlength="40" autocomplete="tel" value="<?= e($values['phone']) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="email">Adresse e-mail *</label><input class="form-control" id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e($values['email']) ?>"></div>
            <div class="col-12"><label class="form-label" for="address">Adresse</label><input class="form-control" id="address" name="address" maxlength="255" autocomplete="street-address" value="<?= e($values['address']) ?>"></div>
            <div class="col-md-6"><label class="form-label" for="formation_id">Formation souhaitée *</label><select class="form-select" id="formation_id" name="formation_id" required><option value="">Choisir une formation</option><?php foreach ($formations as $formation): ?><option value="<?= (int) $formation['id'] ?>" <?= $values['formation_id'] === (string) $formation['id'] ? 'selected' : '' ?>><?= e($formation['title']) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label" for="education_level">Niveau d’études</label><input class="form-control" id="education_level" name="education_level" maxlength="120" value="<?= e($values['education_level']) ?>"></div>
            <div class="col-12"><label class="form-label" for="message">Message complémentaire</label><textarea class="form-control" id="message" name="message" rows="4" maxlength="5000"><?= e($values['message']) ?></textarea></div>
            <div class="col-12"><label class="form-label" for="document">Document justificatif (facultatif, PDF/JPG/PNG, 5 Mo maximum)</label><input class="form-control" id="document" name="document" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
            <div class="col-12"><div class="form-check"><input class="form-check-input" id="privacy" name="privacy" value="accepted" type="checkbox" required><label class="form-check-label" for="privacy">J’accepte que HOMESCHOOL utilise ces informations pour traiter ma demande. *</label></div></div>
            <div class="col-12"><button class="btn btn-primary-custom btn-lg" type="submit" <?= !$databaseReady || !$formations ? 'disabled' : '' ?>>Envoyer ma demande <i class="fa-solid fa-arrow-right ms-2"></i></button></div>
          </div>
        </form>
      </div></div>
    </div></section>
  </main>
  <footer class="site-footer"><div class="container"><p class="mb-0">HOMESCHOOL · Kaolack / Kasnack, en face du CROUS-SS · <a href="mailto:contact@homeschool.sn">contact@homeschool.sn</a></p></div></footer>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/pwa.js"></script>
</body>
</html>
