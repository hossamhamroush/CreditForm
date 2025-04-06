/**
 * Westcon Comstor Middle East
 * Credit Assessment Forms JavaScript
 * Created: April 2025
 */

document.addEventListener('DOMContentLoaded', function() {
    // Form identification and initialization
    const forms = document.querySelectorAll('.multi-step-form');
    forms.forEach(form => {
        initializeMultiStepForm(form);
    });

    // Initialize conditional fields
    initializeConditionalFields();
});

/**
 * Initialize multi-step form functionality
 * @param {HTMLElement} form - The form element to initialize
 */
function initializeMultiStepForm(form) {
    const steps = form.querySelectorAll('.form-step');
    const progressSteps = form.closest('.container').querySelectorAll('.progress-steps li');
    const nextButtons = form.querySelectorAll('.next-btn');
    const prevButtons = form.querySelectorAll('.prev-btn');
    const submitButton = form.querySelector('.submit-btn');

    // Set up next button functionality
    nextButtons.forEach(button => {
        button.addEventListener('click', function() {
            const currentStep = button.closest('.form-step');
            const currentStepIndex = Array.from(steps).indexOf(currentStep);
            
            // Validate current step before proceeding
            if (validateStep(currentStep)) {
                // Hide current step
                currentStep.classList.remove('active');
                
                // Show next step
                steps[currentStepIndex + 1].classList.add('active');
                
                // Update progress indicator
                updateProgressIndicator(progressSteps, currentStepIndex + 1);
                
                // Scroll to top of form
                form.scrollIntoView({ behavior: 'smooth' });
            }
        });
    });

    // Set up previous button functionality
    prevButtons.forEach(button => {
        button.addEventListener('click', function() {
            const currentStep = button.closest('.form-step');
            const currentStepIndex = Array.from(steps).indexOf(currentStep);
            
            // Hide current step
            currentStep.classList.remove('active');
            
            // Show previous step
            steps[currentStepIndex - 1].classList.add('active');
            
            // Update progress indicator
            updateProgressIndicator(progressSteps, currentStepIndex - 1);
            
            // Scroll to top of form
            form.scrollIntoView({ behavior: 'smooth' });
        });
    });

    // Set up form submission
    if (submitButton) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const finalStep = submitButton.closest('.form-step');
            
            if (validateStep(finalStep) && validateEntireForm(form)) {
                // Process form submission
                handleFormSubmission(form);
            }
        });
    }
}

/**
 * Update the progress indicator to show current step
 * @param {NodeList} progressSteps - The progress step elements
 * @param {number} currentIndex - The index of the current step
 */
function updateProgressIndicator(progressSteps, currentIndex) {
    progressSteps.forEach((step, index) => {
        if (index < currentIndex) {
            step.classList.add('completed');
            step.classList.remove('active');
        } else if (index === currentIndex) {
            step.classList.add('active');
            step.classList.remove('completed');
        } else {
            step.classList.remove('active', 'completed');
        }
    });
}

/**
 * Validate a single step of the form
 * @param {HTMLElement} step - The form step to validate
 * @returns {boolean} - Whether the step is valid
 */
function validateStep(step) {
    const requiredFields = step.querySelectorAll('input[required], select[required], textarea[required]');
    let isValid = true;
    
    // Remove existing error messages
    const existingErrorMessages = step.querySelectorAll('.error-message');
    existingErrorMessages.forEach(msg => msg.remove());
    
    // Check each required field
    requiredFields.forEach(field => {
        // Reset field styling
        field.classList.remove('invalid');
        
        // Skip validation for hidden fields (in conditional sections)
        const isHidden = field.closest('.conditional-section') && 
                         field.closest('.conditional-section').style.display === 'none';
        
        if (isHidden) {
            return;
        }
        
        // Validate based on field type
        let fieldValid = true;
        
        if (field.type === 'radio' || field.type === 'checkbox') {
            // For radio buttons and checkboxes, check if any in the group is checked
            const name = field.name;
            const group = step.querySelectorAll(`input[name="${name}"]:checked`);
            fieldValid = group.length > 0;
            
            // Only show error once per group
            if (!fieldValid && !step.querySelector(`.error-message[data-for="${name}"]`)) {
                const label = field.closest('.form-group').querySelector('label');
                showError(label, `Please select an option`, name);
            }
        } else if (field.type === 'email') {
            fieldValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(field.value);
            if (!fieldValid) {
                showError(field, 'Please enter a valid email address');
            }
        } else if (field.type === 'tel') {
            fieldValid = /^[+]?[\d\s()-]{8,20}$/.test(field.value);
            if (!fieldValid) {
                showError(field, 'Please enter a valid phone number');
            }
        } else if (field.type === 'number') {
            fieldValid = !isNaN(field.value) && field.value !== '';
            if (!fieldValid) {
                showError(field, 'Please enter a valid number');
            }
        } else {
            // Text, textarea, select, etc.
            fieldValid = field.value.trim() !== '';
            if (!fieldValid) {
                showError(field, 'This field is required');
            }
        }
        
        if (!fieldValid) {
            isValid = false;
            field.classList.add('invalid');
        }
    });
    
    return isValid;
}

/**
 * Validate the entire form before submission
 * @param {HTMLElement} form - The form to validate
 * @returns {boolean} - Whether the form is valid
 */
function validateEntireForm(form) {
    // Additional validation that spans across multiple steps can be added here
    return true;
}

/**
 * Show error message for a field
 * @param {HTMLElement} field - The field with the error
 * @param {string} message - The error message to display
 * @param {string} [groupName] - Optional group name for radio/checkbox groups
 */
function showError(field, message, groupName = null) {
    const errorElement = document.createElement('div');
    errorElement.className = 'error-message';
    errorElement.textContent = message;
    
    if (groupName) {
        errorElement.setAttribute('data-for', groupName);
    }
    
    // Insert after the field or its label
    if (field.tagName.toLowerCase() === 'label') {
        field.parentNode.insertBefore(errorElement, field.nextSibling);
    } else {
        field.parentNode.insertBefore(errorElement, field.nextSibling);
    }
    
    // Show the error message
    errorElement.style.display = 'block';
}

/**
 * Initialize conditional fields that show/hide based on other field values
 */
function initializeConditionalFields() {
    // Handle conditional inputs
    const conditionalInputs = document.querySelectorAll('.conditional-input');
    conditionalInputs.forEach(input => {
        const conditionField = input.getAttribute('data-condition');
        const conditionValue = input.getAttribute('data-condition-value');
        
        if (conditionField && conditionValue) {
            const triggerElement = document.querySelector(`[name="${conditionField}"]`);
            
            if (triggerElement) {
                // For select elements
                if (triggerElement.tagName.toLowerCase() === 'select') {
                    triggerElement.addEventListener('change', function() {
                        input.style.display = this.value === conditionValue ? 'block' : 'none';
                        
                        // Clear value when hidden
                        if (this.value !== conditionValue) {
                            input.value = '';
                        }
                    });
                    
                    // Initial state
                    input.style.display = triggerElement.value === conditionValue ? 'block' : 'none';
                }
                
                // For radio buttons
                if (triggerElement.type === 'radio') {
                    const radioGroup = document.querySelectorAll(`[name="${conditionField}"]`);
                    radioGroup.forEach(radio => {
                        radio.addEventListener('change', function() {
                            input.style.display = this.value === conditionValue ? 'block' : 'none';
                            
                            // Clear value when hidden
                            if (this.value !== conditionValue) {
                                input.value = '';
                            }
                        });
                    });
                    
                    // Initial state
                    const checkedRadio = document.querySelector(`[name="${conditionField}"]:checked`);
                    if (checkedRadio) {
                        input.style.display = checkedRadio.value === conditionValue ? 'block' : 'none';
                    } else {
                        input.style.display = 'none';
                    }
                }
            }
        }
    });
    
    // Handle conditional sections
    const conditionalSections = document.querySelectorAll('.conditional-section');
    conditionalSections.forEach(section => {
        const conditionField = section.getAttribute('data-condition');
        const conditionValue = section.getAttribute('data-condition-value');
        
        if (conditionField && conditionValue) {
            const radioGroup = document.querySelectorAll(`[name="${conditionField}"]`);
            
            radioGroup.forEach(radio => {
                radio.addEventListener('change', function() {
                    section.style.display = this.value === conditionValue ? 'block' : 'none';
                    
                    // Clear values in the section when hidden
                    if (this.value !== conditionValue) {
                        const inputs = section.querySelectorAll('input, select, textarea');
                        inputs.forEach(input => {
                            input.value = '';
                        });
                    }
                });
            });
            
            // Initial state
            const checkedRadio = document.querySelector(`[name="${conditionField}"]:checked`);
            if (checkedRadio) {
                section.style.display = checkedRadio.value === conditionValue ? 'block' : 'none';
            } else {
                section.style.display = 'none';
            }
        }
    });
}

/**
 * Handle form submission
 * @param {HTMLElement} form - The form being submitted
 */
function handleFormSubmission(form) {
    // In a real implementation, this would send the data to a server
    // For this demo, we'll just show a success message
    
    // Hide the form
    form.style.display = 'none';
    
    // Show the success message
    const successMessage = form.closest('.container').querySelector('.form-submitted-message');
    if (successMessage) {
        // Generate a reference number
        const referenceNumber = generateReferenceNumber();
        const referenceElement = successMessage.querySelector('#referenceNumber');
        if (referenceElement) {
            referenceElement.textContent = referenceNumber;
        }
        
        successMessage.style.display = 'block';
    }
    
    // In a real implementation, you would collect form data and submit it
    
    const formData = new FormData(form);
    
    fetch('/api/submit-credit-assessment', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Handle success
        console.log('Success:', data);
    })
    .catch(error => {
        // Handle error
        console.error('Error:', error);
    });
    
}

/**
 * Generate a reference number for the application
 * @returns {string} - A unique reference number
 */
function generateReferenceNumber() {
    const prefix = 'WC';
    const timestamp = new Date().getTime().toString().slice(-8);
    const random = Math.floor(Math.random() * 10000).toString().padStart(4, '0');
    return `${prefix}-${timestamp}-${random}`;
}
