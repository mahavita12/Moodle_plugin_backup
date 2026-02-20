# Question Helper Plugin

A Moodle local plugin that provides AI-powered assistance for multiple choice questions in quiz attempts using OpenAI's API.

## Features

- **Smart Help Buttons**: Automatically adds "Get Help" buttons to multiple choice questions in quiz attempts
- **AI-Generated Content**: Uses OpenAI to create easier practice questions and concept explanations
- **Usage Limits**: Limits students to 3 help requests per question per session
- **Session-Based Tracking**: No database storage required - uses browser sessionStorage
- **Responsive Design**: Works on desktop and mobile devices
- **Privacy Focused**: No persistent storage of student data

## Installation

1. Copy the plugin files to `moodle/local/questionhelper/`
2. Visit **Site Administration → Notifications** to install the plugin
3. Configure the plugin settings:
   - Go to **Site Administration → Plugins → Local plugins → Question Helper**
   - Enter your OpenAI API key
   - Adjust settings as needed

## Configuration

### Required Settings

- **OpenAI API Key**: Your OpenAI API key (required for functionality)

### Optional Settings

- **Maximum attempts per question**: Number of help requests allowed per question (default: 3)
- **Enable Question Helper**: Toggle to enable/disable the plugin globally

## How It Works

1. **Detection**: The plugin automatically detects multiple choice questions on quiz attempt pages
2. **Button Placement**: Adds a "🤔 Get Help" button near question navigation controls
3. **Help Generation**: When clicked, sends question content to OpenAI API to generate:
   - A simpler practice question with the same concept
   - A brief explanation of the key mathematical concept
4. **Modal Display**: Shows the generated content in a user-friendly popup modal
5. **Usage Tracking**: Tracks attempts per question using browser sessionStorage

## Technical Details

### File Structure

```
local/questionhelper/
├── version.php                     # Plugin metadata
├── lib.php                        # Core functions and hooks
├── settings.php                   # Admin configuration
├── get_help.php                   # AJAX endpoint for OpenAI integration
├── lang/en/local_questionhelper.php # Language strings
├── amd/src/quiz_helper.js         # JavaScript module
├── styles.css                     # CSS styling
└── README.md                      # This documentation
```

### JavaScript Integration

The plugin uses Moodle's AMD module system:

- **Module**: `local_questionhelper/quiz_helper`
- **Initialization**: Automatically loads on quiz attempt pages
- **Dependencies**: jQuery, core/ajax, core/modal_factory

### API Integration

- **Model**: GPT-3.5-turbo (cost-effective for educational content)
- **Timeout**: 10 seconds for API calls
- **Error Handling**: Graceful fallbacks and user-friendly error messages
- **Rate Limiting**: Built-in session-based attempt limiting

## User Experience

### Button States

1. **Available (0-2 attempts)**: Blue "🤔 Get Help" button
2. **Loading**: "Getting help..." with disabled state
3. **Exhausted (3+ attempts)**: Gray "Help exhausted" button (disabled)

### Modal Content

The help modal includes:

- **🎯 Practice Question**: An easier question teaching the same concept
- **💡 Key Concept**: Brief explanation of the mathematical principle
- **Got it!** button to close the modal

## Security Features

- **Session Validation**: Verifies user access to quiz attempts
- **Input Sanitization**: Cleans all data before sending to OpenAI
- **API Key Protection**: Secure storage in Moodle configuration
- **CSRF Protection**: Uses Moodle's sesskey validation

## Privacy & Data Protection

- **No Persistent Storage**: No student data stored in database
- **Session Only**: Attempt tracking cleared when session ends
- **GDPR Compliant**: No collection of personal information
- **API Data**: Question content sent to OpenAI is not stored

## Browser Compatibility

- **Modern Browsers**: Chrome, Firefox, Safari, Edge (latest versions)
- **JavaScript Required**: Plugin requires JavaScript enabled
- **Local Storage**: Uses sessionStorage for attempt tracking

## Troubleshooting

### Plugin Not Working

1. Check that JavaScript is enabled in your browser
2. Verify the plugin is installed and enabled
3. Ensure OpenAI API key is configured correctly
4. Check browser console for JavaScript errors

### Help Button Not Appearing

1. Verify you're on a quiz attempt page with multiple choice questions
2. Check that the plugin is enabled in settings
3. Clear browser cache and reload the page

### API Errors

1. Verify OpenAI API key is valid and has sufficient credits
2. Check network connectivity
3. Review server error logs for detailed error messages

### No Help Content Generated

1. Ensure question contains sufficient text for AI processing
2. Check that multiple choice options are properly formatted
3. Verify API response in browser network tab

## Development

### Extending the Plugin

The plugin is designed to be extensible:

- **Custom Prompts**: Modify the prompt in `get_help.php`
- **Additional Question Types**: Extend JavaScript question detection
- **Custom Styling**: Override CSS classes for theme integration
- **API Integration**: Swap OpenAI for other AI services

### Testing

Test the plugin with:

1. Various question formats and complexity levels
2. Different browsers and screen sizes
3. Network connectivity issues
4. API rate limiting scenarios

## Support

For issues and feature requests:

1. Check the troubleshooting guide above
2. Review browser console for JavaScript errors
3. Check server logs for PHP errors
4. Contact your system administrator

## Version History

- **v1.0.0**: Initial release with core functionality
  - OpenAI integration
  - Session-based attempt tracking
  - Responsive modal interface
  - Admin configuration panel

## License

This plugin is licensed under the GNU General Public License v3.0 or later.
See http://www.gnu.org/licenses/ for full license details.