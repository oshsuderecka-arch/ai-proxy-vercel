// api/gemini.js
import { NextRequest, NextResponse } from 'next/server';

export default async function handler(req) {
  if (req.method !== 'POST') {
    return NextResponse.json({ error: 'Method not allowed' }, { status: 405 });
  }

  try {
    const { model, messages, temperature = 0.7, max_tokens = 2000 } = await req.json();

    // Получаем Gemini API ключ из переменных окружения
    const geminiKey = process.env.GEMINI_API_KEY;
    
    if (!geminiKey) {
      return NextResponse.json({ 
        error: 'Gemini API key not configured' 
      }, { status: 500 });
    }

    // Преобразуем OpenAI формат в Gemini формат
    const systemMessage = messages.find(msg => msg.role === 'system');
    const userMessages = messages.filter(msg => msg.role === 'user');
    
    // Объединяем system и user сообщения
    let fullPrompt = '';
    if (systemMessage) {
      fullPrompt += systemMessage.content + '\n\n';
    }
    userMessages.forEach(msg => {
      fullPrompt += msg.content + '\n\n';
    });

    // Формируем запрос для Gemini API
    const geminiPayload = {
      contents: [
        {
          parts: [
            { text: fullPrompt.trim() }
          ]
        }
      ],
      generationConfig: {
        temperature: temperature,
        maxOutputTokens: max_tokens,
        topK: 40,
        topP: 0.95
      }
    };

    // Определяем модель Gemini
    let geminiModel = 'gemini-1.5-flash'; // По умолчанию
    if (model.includes('gemini-pro')) {
      geminiModel = 'gemini-1.5-pro';
    } else if (model.includes('gemini-1.5-flash')) {
      geminiModel = 'gemini-1.5-flash';
    }

    // Вызываем Gemini API
    const geminiUrl = `https://generativelanguage.googleapis.com/v1/models/${geminiModel}:generateContent?key=${geminiKey}`;
    
    const response = await fetch(geminiUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(geminiPayload)
    });

    if (!response.ok) {
      const errorData = await response.text();
      console.error('Gemini API error:', response.status, errorData);
      return NextResponse.json({ 
        error: `Gemini API error: ${response.status}`,
        details: errorData
      }, { status: response.status });
    }

    const geminiResult = await response.json();

    // Преобразуем ответ Gemini в OpenAI формат
    if (geminiResult.candidates && geminiResult.candidates[0] && geminiResult.candidates[0].content) {
      const content = geminiResult.candidates[0].content.parts[0].text;
      
      const openaiResponse = {
        choices: [
          {
            message: {
              role: 'assistant',
              content: content
            },
            finish_reason: 'stop'
          }
        ],
        usage: {
          prompt_tokens: Math.ceil(fullPrompt.length / 4), // Примерная оценка
          completion_tokens: Math.ceil(content.length / 4),
          total_tokens: Math.ceil((fullPrompt.length + content.length) / 4)
        },
        model: model
      };

      return NextResponse.json(openaiResponse);
    } else {
      return NextResponse.json({ 
        error: 'Invalid Gemini response format' 
      }, { status: 500 });
    }

  } catch (error) {
    console.error('Gemini proxy error:', error);
    return NextResponse.json({ 
      error: 'Internal server error',
      details: error.message 
    }, { status: 500 });
  }
}
