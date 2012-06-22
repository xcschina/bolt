<?php

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware function to check whether a user is logged on.
 */
$checkLogin = function(Request $request) use ($app) {
      
    // There's an active session, we're all good.
    if ($app['session']->has('user')) {
        return;
    } 

    // If the users table is present, but there are no users, and we're on /pilex/users/edit,
    // we let the user stay, because they need to set up the first user. 
    if ($app['storage']->checkUserTableIntegrity() && !$app['users']->getUsers() && $request->getPathInfo()=="/pilex/users/edit/") {
        return;
    } 

    // If there are no users in the users table, or the table doesn't exist. Repair 
    // the DB, and let's add a new user. 
    if (!$app['storage']->checkUserTableIntegrity() || !$app['users']->getUsers()) {
        $app['storage']->repairTables();
        $app['session']->setFlash('info', "There are no users in the database. Please create the first user.");    
        return $app->redirect('/pilex/users/edit');
    }

    $app['session']->setFlash('info', "Please log on.");
    return $app->redirect('/pilex/login');

};


// Temporary hack. Silex should start session on demand.
$app->before(function() use ($app) {
    $app['session']->start();
});



/**
 * Dashboard or "root".
 */
$app->get("/pilex", function(Silex\Application $app) {

    // Check DB-tables integrity
    if (!$app['storage']->checkTablesIntegrity()) {
        $app['session']->setFlash('error', "The database needs to be updated / repaired. Go to 'Settings' > 'Check Database' to do this now.");   
    }

    // get the 'latest' from each of the content types. 
    foreach ($app['config']['contenttypes'] as $key => $contenttype) { 
        $latest[$key] = $app['storage']->getContent($key, array('limit' => 5, 'order' => 'datechanged DESC'));   
    }

    return $app['twig']->render('dashboard.twig', array('latest' => $latest));

})->before($checkLogin);




/**
 * Login page.
 */
$app->match("/pilex/login", function(Silex\Application $app, Request $request) {

    if ($request->getMethod() == "POST") {
      
        $username = makeSlug($request->get('username'));
  
        // echo "<pre>\n" . print_r($request->get('username') , true) . "</pre>\n";
    
        $result = $app['users']->login($request->get('username'), $request->get('password'));
        
        if ($result) {
            return $app->redirect('/pilex');
        }
    
    }
    
    return $app['twig']->render('login.twig');

})->method('GET|POST');


/**
 * Logout page.
 */
$app->get("/pilex/logout", function(Silex\Application $app) {

	$app['session']->setFlash('info', 'You have been logged out.');
    $app['session']->remove('user');
    
    return $app->redirect('/pilex/login');
        
});



/**
 * Check the database, create tables, add missing/new columns to tables
 */
$app->get("/pilex/dbupdate", function(Silex\Application $app) {
	
	$title = "Database check / update";

	$output = $app['storage']->repairTables();
	
	if (empty($output)) {
    	$content = "<p>Your database is already up to date.<p>";
	} else {
    	$content = "<p>Modifications made to the database:<p>";
    	$content .= implode("<br>", $output);
    	$content .= "<p>Your database is now up to date.<p>";
	}
	
	$content .= "<br><br><p><a href='/pilex/prefill'>Fill the database</a> with Loripsum.</p>";
	
	return $app['twig']->render('base.twig', array('title' => $title, 'content' => $content));
	
})->before($checkLogin);


/**
 * Generate some lipsum in the DB.
 */
$app->get("/pilex/prefill", function(Silex\Application $app) {
	
	$title = "Database prefill";

	$content = $app['storage']->preFill();
	
	return $app['twig']->render('base.twig', array('title' => $title, 'content' => $content));
	
})->before($checkLogin);


/**
 * Check the database, create tables, add missing/new columns to tables
 */
$app->get("/pilex/overview/{contenttypeslug}", function(Silex\Application $app, $contenttypeslug) {
	
    $contenttype = $app['storage']->getContentType($contenttypeslug);

	$multiplecontent = $app['storage']->getContent($contenttype['slug'], array('limit' => 100, 'order' => 'datechanged DESC'));

	return $app['twig']->render('overview.twig', array('contenttype' => $contenttype, 'multiplecontent' => $multiplecontent));
	
})->before($checkLogin);


/**
 * Edit a unit of content, or create a new one.
 */
$app->match("/pilex/edit/{contenttypeslug}/{id}", function($contenttypeslug, $id, Silex\Application $app, Request $request) {
        
    $twigvars = array();
    
    $contenttype = $app['storage']->getContentType($contenttypeslug);        
        
    if ($request->getMethod() == "POST") {
        
        // $app['storage']->saveContent($contenttypeslug)
        if ($app['storage']->saveContent($request->request->all(), $contenttype['slug'])) {
        
            if (!empty($id)) {
                $app['session']->setFlash('success', "The changes to this " . $contenttype['singular_name'] . " have been saved."); 
            } else {
                $app['session']->setFlash('success', "The new " . $contenttype['singular_name'] . " have been saved."); 
            }
            return $app->redirect('/pilex/overview/'.$contenttypeslug);
        
        } else {
        
            $app['session']->setFlash('error', "There was an error saving this " . $contenttype['singular_name'] . "."); 
        
        } 
    
    }      
      
	if (!empty($id)) {
      	$content = $app['storage']->getSingleContent($contenttypeslug, array('where' => 'id = '.$id));
	} else {
    	$content = $app['storage']->getEmptyContent($contenttypeslug);
	}

	return $app['twig']->render('editcontent.twig', array('contenttype' => $contenttype, 'content' => $content));
	
})->before($checkLogin)->assert('id', '\d*')->method('GET|POST');



// use Symfony\Component\Form\AbstractType;
// use Symfony\Component\Form\FormBuilder;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\CallbackValidator;
use Symfony\Component\Validator\Constraints as Assert;

$app->match("/pilex/users/edit/{id}", function($id, Silex\Application $app, Request $request) {
    
    // Get the user we want to edit (if any)    
    if (!empty($id)) {
        $user = $app['users']->getUser($id);
        $title = "Edit a user";
    } else {
        $user = $app['users']->getEmptyUser();
        $title = "Create a new user";
    }
    
    $userlevels = $app['users']->getUserLevels();
    $enabledoptions = array(1 => 'yes', 0 => 'no');
    // If we're creating the first user, we should make sure that we can only create
    // a user that's allowed to log on.
    if(!$app['users']->getUsers()) {
        $userlevels = array_slice($userlevels, -1);
        $enabledoptions = array(1 => 'yes');
        $title = "Create the first user";        
    }
    
    // Start building the form..
    $form = $app['form.factory']->createBuilder('form', $user)
        ->add('id', 'hidden')
        ->add('username', 'text', array(
            'constraints' => array(new Assert\NotBlank(), new Assert\MinLength(2))
        ));
        
    // If we're adding a new user, the password will be mandatory. If we're
    // editing an existing user, we can leave it blank
    if (empty($id)) {
        $form->add('password', 'password', array(
                'constraints' => array(new Assert\NotBlank(), new Assert\MinLength(6)),
            ))
            ->add('password_confirmation', 'password', array(
                'constraints' => array(new Assert\NotBlank(), new Assert\MinLength(6)),
                'label' => "Password (confirmation)"
            ));
    } else {
        $form->add('password', 'password', array(
                'required' => false
            ))
            ->add('password_confirmation', 'password', array(
                'required' => false,
                'label' => "Password (confirmation)"
            ));        
    }
        
    // Contiue with the rest of the fields.
    $form->add('email', 'text', array(
            'constraints' => new Assert\Email(),
        ))
        ->add('displayname', 'text', array(
            'constraints' => array(new Assert\NotBlank(), new Assert\MinLength(2))
        ))
        ->add('userlevel', 'choice', array(
            'choices' => $userlevels, 
            'expanded' => false,
            'constraints' => new Assert\Choice(array_keys($userlevels)) 
        ))
        ->add('enabled', 'choice', array(
            'choices' => $enabledoptions, 
            'expanded' => false,
            'constraints' => new Assert\Choice(array_keys($enabledoptions)), 
            'label' => "User is enabled",
        ))
        ->add('lastseen', 'text', array('disabled' => true))
        ->add('lastip', 'text', array('disabled' => true));
        
    // Make sure the passwords are identical with a custom validator..
    $form->addValidator(new CallbackValidator(function($form) {
    
        $pass1 = $form['password']->getData();
        $pass2 = $form['password_confirmation']->getData();
    
        // Some checks for the passwords.. 
        if (!empty($pass1) && strlen($pass1)<6 ) {
            $form['password']->addError(new FormError('This value is too short. It should have 6 characters or more.'));
        } else if ($pass1 != $pass2 ) {
            $form['password_confirmation']->addError(new FormError('Passwords must match.'));
        }
                    
    }));
        
    $form = $form->getForm();
       
    // Check if the form was POST-ed, and valid. If so, store the user.
    if ($request->getMethod() == "POST") {
        $form->bindRequest($request);

        if ($form->isValid()) {
            
            $user = $form->getData();
        
            $res = $app['users']->saveUser( $user );
            
            if ($res) {
                $app['session']->setFlash('success', "User " . $user['username'] . " has been saved."); 
            } else {
                $app['session']->setFlash('error', "User " . $user['username'] . " could not be saved, or nothing was changed."); 
            }
            
            return $app->redirect('/pilex/users');
            
        }
    }

    return $app['twig']->render('edituser.twig', array(
        'form' => $form->createView(),
        'title' => $title
        ));      
      
})->before($checkLogin)->assert('id', '\d*')->method('GET|POST');



/**
 * Check the database, create tables, add missing/new columns to tables
 */
$app->get("/pilex/users", function(Silex\Application $app) {
	
	$title = "Users";
    $users = $app['users']->getUsers();
    
	return $app['twig']->render('users.twig', array('users' => $users, 'title' => $title));
	
})->before($checkLogin);




/**
 * Error page.
 */
$app->error(function(Exception $e) use ($app) {

    $app['monolog']->addError(json_encode(array(
        'class' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'trace' => $e->getTrace()
        )));

    $twigvars = array();

    $twigvars['class'] = get_class($e);
    $twigvars['message'] = $e->getMessage();
    $twigvars['code'] = $e->getCode();

	$trace = $e->getTrace();;

	unset($trace[0]['args']);

    $twigvars['trace'] = print_r($trace[0], true);

    $twigvars['title'] = "An error has occured!";

    return $app['twig']->render('error.twig', $twigvars);

});